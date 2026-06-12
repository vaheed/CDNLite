<?php

require __DIR__ . '/app/Support/bootstrap.php';

use App\Modules\Collector\Http\Controllers\CollectorController;
use App\Modules\Collector\Services\CollectorService;
use App\Modules\Admin\Http\Controllers\AdminAuthController;
use App\Modules\Admin\Services\AdminAuthService;
use App\Modules\Dns\Http\Controllers\DnsController;
use App\Modules\Dns\Http\Controllers\EdgeNetworkController;
use App\Modules\Dns\Services\DnsService;
use App\Modules\Edge\Http\Controllers\EdgeController;
use App\Modules\Edge\Services\EdgeAuthService;
use App\Modules\Edge\Services\EdgeService;
use App\Modules\Proxy\Http\Controllers\OriginController;
use App\Modules\Proxy\Http\Controllers\TrafficRulesController;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Proxy\Services\OriginHealthService;
use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Domains\Http\Controllers\DomainController;
use App\Modules\Domains\Services\DomainService;
use App\Modules\Health\Http\Controllers\ReadinessController;
use App\Modules\Health\Services\ReadinessService;
use App\Modules\Overview\Http\Controllers\OverviewController;
use App\Modules\Overview\Services\OverviewService;
use App\Modules\Operations\Http\Controllers\OperationsLogController;
use App\Modules\Operations\Services\OperationsLogService;
use App\Modules\Settings\Http\Controllers\SettingsController;
use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\ApiAuth;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

function truthyEnv(string $name, bool $default = false): bool
{
    $raw = getenv($name);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $value = strtolower(trim($raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function listEnv(string $name, string $default = ''): array
{
    $raw = getenv($name);
    $value = $raw === false || trim($raw) === '' ? $default : (string) $raw;
    return array_values(array_filter(array_map(static fn (string $item): string => trim($item), explode(',', $value)), static fn (string $item): bool => $item !== ''));
}

function applyCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!is_string($origin) || trim($origin) === '') {
        return;
    }

    $allowed = listEnv('CDNLITE_CORS_ALLOWED_ORIGINS', 'http://localhost:8082,http://127.0.0.1:8082');
    if (!in_array('*', $allowed, true) && !in_array($origin, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . (in_array('*', $allowed, true) ? '*' : $origin));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CDNLITE-Edge-Id, X-CDNLITE-Timestamp, X-CDNLITE-Nonce, X-CDNLITE-Signature');
    header('Access-Control-Max-Age: 600');
}

function respond(array $payload, int $defaultStatus = 200): void
{
    global $requestStartedAt, $method, $path;

    $status = isset($payload['status']) ? (int) $payload['status'] : $defaultStatus;
    $error = isset($payload['error']) ? (string) $payload['error'] : null;
    unset($payload['status']);

    $durationMs = (int) round((microtime(true) - $requestStartedAt) * 1000);
    $context = [
        'method' => (string) $method,
        'path' => (string) $path,
        'status' => $status,
        'duration_ms' => $durationMs,
    ];
    if ($error !== null && $error !== '') {
        $context['error'] = $error;
        Logger::warn('http_request_failed', $context);
    } else {
        Logger::info('http_request', $context);
    }

    http_response_code($status);
    if (isset($payload['html']) && is_string($payload['html'])) {
        echo $payload['html'];
        exit;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function headerValue(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        return trim($_SERVER[$key]);
    }

    if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return null;
}

function bearerToken(): string
{
    $raw = headerValue('Authorization') ?? '';
    if (str_starts_with($raw, 'Bearer ')) {
        return trim(substr($raw, 7));
    }

    return '';
}

function edgeSignature(): string
{
    return (string) (headerValue('X-CDNLITE-Signature') ?? '');
}

function requireApiAuth(): void
{
    if (!ApiAuth::requiresAuth()) {
        return;
    }

    if (!ApiAuth::isValid(bearerToken())) {
        respond(['error' => 'api_auth_required'], 401);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestStartedAt = microtime(true);
applyCors();
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$bodyRaw = file_get_contents('php://input');
$body = [];
if ($bodyRaw !== false && trim($bodyRaw) !== '') {
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_starts_with($contentType, 'application/x-www-form-urlencoded') || str_starts_with($contentType, 'multipart/form-data')) {
        $body = $_POST;
    } else {
        $decoded = json_decode($bodyRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            respond(['error' => 'invalid_json', 'detail' => json_last_error_msg()], 400);
        }
        if (!is_array($decoded)) {
            respond(['error' => 'invalid_json_object_expected'], 400);
        }
        $body = $decoded;
    }
}
set_exception_handler(static function (\Throwable $e): void {
    Logger::error('uncaught_exception', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $payload = ['error' => 'internal_server_error'];
    if (Logger::isDebug()) {
        $payload['detail'] = $e->getMessage();
    }
    respond($payload, 500);
});

$request = new Request($method, (string) $path, $_GET, is_array($body) ? $body : [], (string) ($bodyRaw ?: ''));

$domainService = new DomainService();
$dnsService = new DnsService();
$edgeService = new EdgeService();
$domainController = new DomainController($domainService);
$dnsController = new DnsController($dnsService);
$edgeNetworkController = new EdgeNetworkController();
$edgeController = new EdgeController($edgeService);
$collectorController = new CollectorController(new CollectorService());
$configService = new ConfigService($domainService, $dnsService);
$rulesController = new TrafficRulesController(new TrafficRulesService());
$originController = new OriginController(new OriginHealthService());
$edgeAuth = new EdgeAuthService();
$adminAuth = new AdminAuthService();
$adminAuthController = new AdminAuthController($adminAuth);
$readinessController = new ReadinessController(new ReadinessService());
$settingsController = new SettingsController(new SettingsRepository());
$overviewController = new OverviewController(new OverviewService());
$operationsLogController = new OperationsLogController(new OperationsLogService());

if (truthyEnv('CDNLITE_BOOTSTRAP_EDGE_TOKEN', false)) {
    $bootstrapEdgeId = trim((string) (getenv('CDNLITE_BOOTSTRAP_EDGE_ID') ?: getenv('EDGE_ID') ?: ''));
    $bootstrapToken = trim((string) (getenv('CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE') ?: getenv('EDGE_TOKEN') ?: ''));
    if ($bootstrapEdgeId !== '' && $bootstrapToken !== '') {
        try {
            $edgeService->registerToken($bootstrapEdgeId, $bootstrapToken);
        } catch (\Throwable $e) {
            Logger::warn('edge_token_bootstrap_failed', ['error' => $e->getMessage()]);
        }
    }
}

if (truthyEnv('CDNLITE_BOOTSTRAP_ADMIN_USER', false)) {
    $bootstrapAdminUsername = trim((string) (getenv('CDNLITE_BOOTSTRAP_ADMIN_USERNAME') ?: ''));
    $bootstrapAdminPassword = (string) (getenv('CDNLITE_BOOTSTRAP_ADMIN_PASSWORD') ?: '');
    $bootstrapAdminDisplayName = trim((string) (getenv('CDNLITE_BOOTSTRAP_ADMIN_DISPLAY_NAME') ?: 'Bootstrap Admin'));
    if ($bootstrapAdminUsername !== '' && $bootstrapAdminPassword !== '') {
        try {
            $adminAuth->bootstrapUser($bootstrapAdminUsername, $bootstrapAdminPassword, $bootstrapAdminDisplayName);
        } catch (\Throwable $e) {
            Logger::warn('admin_user_bootstrap_failed', ['error' => $e->getMessage()]);
        }
    }
}

$router = new Router();
$router->add('POST', '/api/v1/admin/login', static fn (Request $req): array => $adminAuthController->login($req));
$router->add('GET', '/api/v1/admin/me', static fn (): array => $adminAuthController->me(bearerToken()), auth: true);
$router->add('POST', '/api/v1/admin/logout', static fn (): array => $adminAuthController->logout(bearerToken()), auth: true);
$router->add('GET', '/health', static fn (): array => Response::json(['ok' => true, 'time' => time()]));
$router->add('GET', '/cdn-health', static fn (): array => Response::json($readinessController->index()));
$router->add('GET', '/ready', static function () use ($configService): array {
    $checks = ['postgres' => 'ok', 'schema' => 'ok', 'config_generation' => 'ok'];
    try {
        \App\Support\Database::pdo()->query('SELECT 1');
    } catch (\Throwable) {
        $checks['postgres'] = 'fail';
    }
    try {
        $required = ['domains', 'redirect_rules', 'rate_limit_rules', 'waf_rules', 'cache_rules', 'config_state', 'config_snapshots'];
        foreach ($required as $table) {
            $stmt = \App\Support\Database::pdo()->query("SELECT to_regclass('public." . $table . "')");
            if ($stmt->fetchColumn() === null) {
                $checks['schema'] = 'fail';
                break;
            }
        }
    } catch (\Throwable) {
        $checks['schema'] = 'fail';
    }
    try {
        $configService->buildSnapshotForVersion(null);
    } catch (\Throwable) {
        $checks['config_generation'] = 'fail';
    }
    if (ApiAuth::productionMissingToken()) {
        $checks['api_token'] = 'fail';
    } else {
        $checks['api_token'] = ApiAuth::isConfigured() ? 'ok' : 'warn';
    }
    $ok = !in_array('fail', $checks, true);
    return Response::json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503);
});
$router->add('GET', '/api/v1/readiness', static fn (): array => Response::json($readinessController->index()), auth: true);
$router->add('GET', '/api/v1/overview', static fn (): array => Response::json($overviewController->index()), auth: true);
$router->add('GET', '/api/v1/overview/warnings', static fn (): array => Response::json($overviewController->warnings()), auth: true);
$router->add('GET', '/api/v1/security/events', static fn (Request $req): array => Response::json($operationsLogController->securityEvents($req->query)), auth: true);
$router->add('GET', '/api/v1/security/summary', static fn (Request $req): array => Response::json($operationsLogController->securitySummary($req->query)), auth: true);
$router->add('GET', '/api/v1/audit', static fn (Request $req): array => Response::json($operationsLogController->audit($req->query)), auth: true);
$router->add('GET', '/api/v1/config/snapshots', static fn (): array => Response::json(['data' => $configService->snapshots()]), auth: true);
$router->add('GET', '/api/v1/config/snapshots/{version}', static function (Request $req, array $p) use ($configService): array {
    $snapshot = $configService->snapshot((int) $p['version']);
    return $snapshot === null
        ? Response::json(['error' => 'config_snapshot_not_found'], 404)
        : Response::json(['data' => $snapshot]);
}, auth: true);
$router->add('POST', '/api/v1/config/snapshots/diff', static function (Request $req) use ($configService): array {
    try {
        return Response::json(['data' => $configService->diff((int) ($req->body['from_version'] ?? 0), (int) ($req->body['to_version'] ?? 0))]);
    } catch (\OutOfBoundsException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
}, auth: true);
$router->add('POST', '/api/v1/config/snapshots/{version}/rollback', static function (Request $req, array $p) use ($configService): array {
    try {
        return Response::json(['data' => $configService->rollback((int) $p['version'])]);
    } catch (\OutOfBoundsException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
}, auth: true);
$router->add('POST', '/api/v1/config/snapshots/rebuild', static fn (): array => Response::json(['data' => $configService->rebuild()]), auth: true);
$router->add('GET', '/api/v1/settings', static fn (): array => Response::json($settingsController->index()), auth: true);
$router->add('GET', '/api/v1/settings/{group}', static function (Request $req, array $p) use ($settingsController): array {
    try {
        return Response::json($settingsController->show((string) $p['group']));
    } catch (\InvalidArgumentException) {
        return Response::json(['error' => 'settings_group_not_found'], 404);
    }
}, auth: true);
$router->add('PATCH', '/api/v1/settings/{group}', static function (Request $req, array $p) use ($settingsController, $adminAuth): array {
    try {
        $user = $adminAuth->userForToken(bearerToken());
        $actor = $user['username'] ?? (ApiAuth::isConfigured() ? 'api-token' : 'anonymous-admin');
        return Response::json($settingsController->update((string) $p['group'], $req->body, (string) $actor));
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 422);
    }
}, auth: true);
$router->add('POST', '/api/v1/settings/validate', static function (Request $req) use ($settingsController): array {
    try {
        $result = $settingsController->validate($req->body);
        return Response::json($result, $result['valid'] ? 200 : 422);
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 422);
    }
}, auth: true);
$router->add('POST', '/api/v1/settings/test/powerdns', static fn (): array => Response::json($settingsController->testPowerDns()), auth: true);
$router->add('GET', '/api/v1/edge-countries', static fn (): array => Response::json($edgeNetworkController->countries()), auth: true);

$router->add('GET', '/api/v1/domains', static fn () => Response::json($domainController->index()), auth: true);
$router->add('POST', '/api/v1/domains', static function (Request $req) use ($domainController): array {
    $result = $domainController->store($req->body);
    return Response::json($result, (int) ($result['status'] ?? 201));
}, auth: true);
$router->add('GET', '/api/v1/domains/{domainId}', static function (Request $req, array $p) use ($domainController): array {
    $result = $domainController->show((string) $p['domainId']);
    return $result ? Response::json($result) : Response::json(['error' => 'domain_not_found'], 404);
}, auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}', static function (Request $req, array $p) use ($domainController): array {
    $result = $domainController->update((string) $p['domainId'], $req->body);
    return $result === null ? Response::json(['error' => 'domain_not_found'], 404) : Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}', static fn (Request $req, array $p) => Response::json($domainController->delete((string) $p['domainId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/verify-nameservers', static function (Request $req, array $p) use ($domainController): array {
    $result = $domainController->verifyNameservers((string) $p['domainId']);
    return $result === null ? Response::json(['error' => 'domain_not_found'], 404) : Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/activate', static function (Request $req, array $p) use ($domainController): array {
    $result = $domainController->activate((string) $p['domainId'], $req->body);
    return $result === null ? Response::json(['error' => 'domain_not_found'], 404) : Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/dns/records', static function (Request $req, array $p) use ($dnsController): array {
    $result = $dnsController->create((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 201));
}, auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/dns/records', static fn (Request $req, array $p) => Response::json($dnsController->list((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/dns/records/{recordId}', static function (Request $req, array $p) use ($dnsController): array {
    $result = $dnsController->update((string) $p['domainId'], (string) $p['recordId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/dns/records/{recordId}', static fn (Request $req, array $p) => Response::json($dnsController->delete((string) $p['domainId'], (string) $p['recordId'])), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/routing', static fn (Request $req, array $p) => Response::json($dnsController->routing((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/routing', static fn (Request $req, array $p) => Response::json($dnsController->updateRouting((string) $p['domainId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/dns/records/{recordId}/preview-routing', static fn (Request $req, array $p) => Response::json($dnsController->previewRouting((string) $p['domainId'], (string) $p['recordId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes', static fn (Request $req, array $p) => Response::json($dnsController->geoRoutes((string) $p['domainId'], (string) $p['recordId'])), auth: true);
$router->add('PUT', '/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes', static function (Request $req, array $p) use ($dnsController): array {
    $result = $dnsController->updateGeoRoutes((string) $p['domainId'], (string) $p['recordId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);

$router->add('GET', '/api/v1/domains/{domainId}/origins', static fn (Request $req, array $p) => Response::json($originController->list((string) $p['domainId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/origins', static function (Request $req, array $p) use ($originController): array {
    $result = $originController->create((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 201));
}, auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/origins/{originId}', static function (Request $req, array $p) use ($originController): array {
    $result = $originController->update((string) $p['domainId'], (string) $p['originId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/origins/{originId}', static fn (Request $req, array $p) => Response::json($originController->delete((string) $p['domainId'], (string) $p['originId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/origins/{originId}/check', static function (Request $req, array $p) use ($originController): array {
    $result = $originController->check((string) $p['domainId'], (string) $p['originId']);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);

$router->add('POST', '/api/v1/domains/{domainId}/redirects', static fn (Request $req, array $p) => Response::json($rulesController->createRedirect((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/redirects', static fn (Request $req, array $p) => Response::json($rulesController->listRedirects((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/redirects/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updateRedirect((string) $p['domainId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/redirects/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteRedirect((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/redirects/import', static fn (Request $req, array $p) => Response::json($rulesController->importRedirects((string) $p['domainId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/redirects/export', static fn (Request $req, array $p) => Response::json($rulesController->exportRedirects((string) $p['domainId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/redirects/test', static fn (Request $req, array $p) => Response::json($rulesController->testRedirect((string) $p['domainId'], $req->body)), auth: true);
$router->add('PUT', '/api/v1/domains/{domainId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->setRateLimit((string) $p['domainId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->getRateLimit((string) $p['domainId'])), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->disableRateLimit((string) $p['domainId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/rate-limits', static fn (Request $req, array $p) => Response::json($rulesController->createRateLimit((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/rate-limits', static fn (Request $req, array $p) => Response::json($rulesController->listRateLimits((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/rate-limits/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updateRateLimit((string) $p['domainId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/rate-limits/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteRateLimit((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/waf-rules', static fn (Request $req, array $p) => Response::json($rulesController->createWaf((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/waf-rules', static fn (Request $req, array $p) => Response::json($rulesController->listWaf((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/waf-rules/{wafId}', static fn (Request $req, array $p) => Response::json($rulesController->updateWaf((string) $p['domainId'], (string) $p['wafId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/waf-rules/{wafId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteWaf((string) $p['domainId'], (string) $p['wafId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/headers', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->createHeaderRule((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 201));
}, auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/headers', static fn (Request $req, array $p) => Response::json($rulesController->listHeaderRules((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/headers/{ruleId}', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->updateHeaderRule((string) $p['domainId'], (string) $p['ruleId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/headers/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteHeaderRule((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ip-rules', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->createIpRule((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 201));
}, auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/ip-rules', static fn (Request $req, array $p) => Response::json($rulesController->listIpRules((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/ip-rules/{ruleId}', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->updateIpRule((string) $p['domainId'], (string) $p['ruleId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/ip-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteIpRule((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/cache-rules', static fn (Request $req, array $p) => Response::json($rulesController->createCacheRule((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/cache-rules', static fn (Request $req, array $p) => Response::json($rulesController->listCacheRules((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/cache-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updateCacheRule((string) $p['domainId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/cache-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteCacheRule((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/cache/settings', static fn (Request $req, array $p) => Response::json($rulesController->getDomainCacheSettings((string) $p['domainId'])), auth: true);
$router->add('PUT', '/api/v1/domains/{domainId}/cache/settings', static fn (Request $req, array $p) => Response::json($rulesController->setDomainCacheSettings((string) $p['domainId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/cache/purge', static fn (Request $req, array $p) => Response::json($rulesController->createCachePurgeRequest((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/cache/purge-requests', static fn (Request $req, array $p) => Response::json($rulesController->listCachePurgeRequests((string) $p['domainId'])), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/cache/purge-requests/{requestId}', static fn (Request $req, array $p) => Response::json($rulesController->getCachePurgeRequest((string) $p['domainId'], (string) $p['requestId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/page-rules', static fn (Request $req, array $p) => Response::json($rulesController->createPageRule((string) $p['domainId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/page-rules', static fn (Request $req, array $p) => Response::json($rulesController->listPageRules((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/page-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updatePageRule((string) $p['domainId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/domains/{domainId}/page-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deletePageRule((string) $p['domainId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/page-rules/test', static fn (Request $req, array $p) => Response::json($rulesController->testPageRule((string) $p['domainId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/ssl/certificates', static fn (Request $req, array $p) => Response::json($rulesController->listSslCertificates((string) $p['domainId'])), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/ssl', static fn (Request $req, array $p) => Response::json($rulesController->getSslSettings((string) $p['domainId'])), auth: true);
$router->add('PATCH', '/api/v1/domains/{domainId}/ssl/settings', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->setSslSettings((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 200));
}, auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/request', static fn (Request $req, array $p) => Response::json($rulesController->requestSslCertificate((string) $p['domainId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/acme/issue', static fn (Request $req, array $p) => Response::json($rulesController->issueAcmeCertificate((string) $p['domainId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/request-cert', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->requestAutomatedSslCertificate((string) $p['domainId'], $req->body);
    return Response::json($result, (int) ($result['status'] ?? 202));
}, auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/renew', static function (Request $req, array $p) use ($rulesController): array {
    $result = $rulesController->forceRenewSslCertificate((string) $p['domainId']);
    return Response::json($result, (int) ($result['status'] ?? 202));
}, auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/ssl/acme-status', static fn (Request $req, array $p) => Response::json($rulesController->acmeStatus((string) $p['domainId'])), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/check', static fn (Request $req, array $p) => Response::json($rulesController->checkSslCertificates((string) $p['domainId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/domains/{domainId}/ssl/manual-certificate', static fn (Request $req, array $p) => Response::json($rulesController->importManualSslCertificate((string) $p['domainId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/security/events', static fn (Request $req, array $p) => Response::json($rulesController->listSecurityEvents((string) $p['domainId'], $req->query)), auth: true);
$router->add('GET', '/api/v1/analytics/cache', static fn (Request $req) => Response::json($collectorController->cacheAnalytics(isset($req->query['domain_id']) ? (string) $req->query['domain_id'] : null)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/analytics/summary', static fn (Request $req, array $p) => Response::json($collectorController->summary((string) $p['domainId'], isset($req->query['bucket']) ? (string) $req->query['bucket'] : null)), auth: true);
$router->add('GET', '/api/v1/domains/{domainId}/analytics/cache', static fn (Request $req, array $p) => Response::json($collectorController->cacheAnalytics((string) $p['domainId'])), auth: true);

$router->add('GET', '/api/v1/edge/nodes', static fn () => Response::json($edgeController->list()), auth: true);
$router->add('GET', '/api/v1/edges/pools', static fn () => Response::json($edgeController->pools()), auth: true);
$router->add('GET', '/api/v1/edges/dns', static fn () => Response::json($edgeController->dns()), auth: true);
$router->add('POST', '/api/v1/edge/register', static fn (Request $req) => Response::json($edgeController->register($req->body)), edgeAuth: true);
$router->add('POST', '/api/v1/edge/heartbeat', static fn (Request $req) => Response::json($edgeController->heartbeat($req->body)), edgeAuth: true);
$router->add('GET', '/api/v1/edge/config', static fn (Request $req) => Response::json($configService->buildSnapshotForVersion(isset($req->query['if_version']) ? (int) $req->query['if_version'] : null)), edgeAuth: true);
$router->add('POST', '/api/v1/collector/usage', static fn (Request $req) => Response::json($collectorController->ingest($req->body)), edgeAuth: true);
$router->add('POST', '/api/v1/collector/security-events', static fn (Request $req) => Response::json($collectorController->ingestSecurityEvents($req->body)), edgeAuth: true);
$router->add('GET', '/api/v1/usage/summary', static fn (Request $req) => Response::json($collectorController->summary(isset($req->query['domain_id']) ? (string) $req->query['domain_id'] : null, isset($req->query['bucket']) ? (string) $req->query['bucket'] : null)), auth: true);
$router->add('POST', '/api/v1/usage/recalculate', static fn (Request $req) => Response::json($collectorController->recalculate($req->body)), auth: true);

$matched = $router->dispatch($request);
if ($matched === null) {
    respond(['error' => 'not_found'], 404);
}

$route = $matched['route'];
$params = $matched['params'];

if (($route['auth'] ?? false) === true) {
    requireApiAuth();
}

if (($route['edge_auth'] ?? false) === true) {
    $edgeIdHeader = headerValue('X-CDNLITE-Edge-Id') ?? '';
    if (in_array($request->path, ['/api/v1/edge/register', '/api/v1/edge/heartbeat'], true)
        && ($edgeIdHeader === '' || $edgeIdHeader !== (string) ($request->body['edge_id'] ?? ''))) {
        respond(['error' => 'edge_auth_edge_id_mismatch'], 401);
    }

    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNLITE-Timestamp') ?? 0),
        (string) (headerValue('X-CDNLITE-Nonce') ?? ''),
        $request->method,
        $request->path,
        in_array($request->method, ['POST', 'PUT', 'PATCH'], true) ? $request->rawBody : '',
        edgeSignature()
    );

    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
}

$result = ($route['handler'])($request, $params);
if (is_array($result) && array_key_exists('payload', $result) && array_key_exists('status', $result)) {
    respond((array) $result['payload'], (int) $result['status']);
}
if (is_array($result)) {
    respond($result, 200);
}
respond(['error' => 'invalid_route_response'], 500);
