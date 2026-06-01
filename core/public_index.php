<?php

require __DIR__ . '/app/Support/bootstrap.php';

use App\Modules\Collector\Http\Controllers\CollectorController;
use App\Modules\Collector\Services\CollectorService;
use App\Modules\Dns\Http\Controllers\DnsController;
use App\Modules\Dns\Services\DnsService;
use App\Modules\Edge\Http\Controllers\EdgeController;
use App\Modules\Edge\Services\EdgeAuthService;
use App\Modules\Edge\Services\EdgeService;
use App\Modules\Proxy\Http\Controllers\TrafficRulesController;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Sites\Http\Controllers\SiteController;
use App\Modules\Sites\Services\SiteService;
use App\Support\ApiAuth;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;

header('Content-Type: application/json');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

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
$bodyRaw = file_get_contents('php://input');
$body = [];
if ($bodyRaw !== false && trim($bodyRaw) !== '') {
    $decoded = json_decode($bodyRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['error' => 'invalid_json', 'detail' => json_last_error_msg()], 400);
    }
    if (!is_array($decoded)) {
        respond(['error' => 'invalid_json_object_expected'], 400);
    }
    $body = $decoded;
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

$siteService = new SiteService();
$dnsService = new DnsService();
$siteController = new SiteController($siteService);
$dnsController = new DnsController($dnsService);
$edgeController = new EdgeController(new EdgeService());
$collectorController = new CollectorController(new CollectorService());
$configService = new ConfigService($siteService, $dnsService);
$rulesController = new TrafficRulesController(new TrafficRulesService());
$edgeAuth = new EdgeAuthService();

$router = new Router();
$router->add('GET', '/health', static fn (): array => Response::json(['ok' => true, 'time' => time()]));
$router->add('GET', '/ready', static function () use ($configService): array {
    $checks = ['postgres' => 'ok', 'schema' => 'ok', 'config_generation' => 'ok'];
    try {
        \App\Support\Database::pdo()->query('SELECT 1');
    } catch (\Throwable) {
        $checks['postgres'] = 'fail';
    }
    try {
        $required = ['sites', 'redirect_rules', 'rate_limit_rules', 'waf_rules', 'cache_rules', 'config_state', 'config_snapshots'];
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

$router->add('GET', '/api/v1/sites', static fn () => Response::json($siteController->index()), auth: true);
$router->add('POST', '/api/v1/sites', static fn (Request $req) => Response::json($siteController->store($req->body), 201), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}', static function (Request $req, array $p) use ($siteController): array {
    $result = $siteController->update((string) $p['siteId'], $req->body);
    return $result === null ? Response::json(['error' => 'site_not_found'], 404) : Response::json($result);
}, auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}', static fn (Request $req, array $p) => Response::json($siteController->delete((string) $p['siteId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/proxy/enable', static function (Request $req, array $p) use ($siteController): array {
    $result = $siteController->enableProxy((string) $p['siteId']);
    return $result === null ? Response::json(['error' => 'site_not_found'], 404) : Response::json($result);
}, auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/proxy/disable', static function (Request $req, array $p) use ($siteController): array {
    $result = $siteController->disableProxy((string) $p['siteId']);
    return $result === null ? Response::json(['error' => 'site_not_found'], 404) : Response::json($result);
}, auth: true);

$router->add('POST', '/api/v1/sites/{siteId}/dns/records', static fn (Request $req, array $p) => Response::json($dnsController->create((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/dns/records', static fn (Request $req, array $p) => Response::json($dnsController->list((string) $p['siteId'])), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}/dns/records/{recordId}', static fn (Request $req, array $p) => Response::json($dnsController->update((string) $p['siteId'], (string) $p['recordId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/dns/records/{recordId}', static fn (Request $req, array $p) => Response::json($dnsController->delete((string) $p['siteId'], (string) $p['recordId'])), auth: true);

$router->add('POST', '/api/v1/sites/{siteId}/redirects', static fn (Request $req, array $p) => Response::json($rulesController->createRedirect((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/redirects', static fn (Request $req, array $p) => Response::json($rulesController->listRedirects((string) $p['siteId'])), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}/redirects/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updateRedirect((string) $p['siteId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/redirects/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteRedirect((string) $p['siteId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/redirects/import', static fn (Request $req, array $p) => Response::json($rulesController->importRedirects((string) $p['siteId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/redirects/export', static fn (Request $req, array $p) => Response::json($rulesController->exportRedirects((string) $p['siteId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/redirects/test', static fn (Request $req, array $p) => Response::json($rulesController->testRedirect((string) $p['siteId'], $req->body)), auth: true);
$router->add('PUT', '/api/v1/sites/{siteId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->setRateLimit((string) $p['siteId'], $req->body)), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->getRateLimit((string) $p['siteId'])), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/rate-limit', static fn (Request $req, array $p) => Response::json($rulesController->disableRateLimit((string) $p['siteId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/waf-rules', static fn (Request $req, array $p) => Response::json($rulesController->createWaf((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/waf-rules', static fn (Request $req, array $p) => Response::json($rulesController->listWaf((string) $p['siteId'])), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}/waf-rules/{wafId}', static fn (Request $req, array $p) => Response::json($rulesController->updateWaf((string) $p['siteId'], (string) $p['wafId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/waf-rules/{wafId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteWaf((string) $p['siteId'], (string) $p['wafId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/cache-rules', static fn (Request $req, array $p) => Response::json($rulesController->createCacheRule((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/cache-rules', static fn (Request $req, array $p) => Response::json($rulesController->listCacheRules((string) $p['siteId'])), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}/cache-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updateCacheRule((string) $p['siteId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/cache-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deleteCacheRule((string) $p['siteId'], (string) $p['ruleId'])), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/cache/settings', static fn (Request $req, array $p) => Response::json($rulesController->getSiteCacheSettings((string) $p['siteId'])), auth: true);
$router->add('PUT', '/api/v1/sites/{siteId}/cache/settings', static fn (Request $req, array $p) => Response::json($rulesController->setSiteCacheSettings((string) $p['siteId'], $req->body)), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/cache/purge', static fn (Request $req, array $p) => Response::json($rulesController->createCachePurgeRequest((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/cache/purge-requests', static fn (Request $req, array $p) => Response::json($rulesController->listCachePurgeRequests((string) $p['siteId'])), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/cache/purge-requests/{requestId}', static fn (Request $req, array $p) => Response::json($rulesController->getCachePurgeRequest((string) $p['siteId'], (string) $p['requestId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/page-rules', static fn (Request $req, array $p) => Response::json($rulesController->createPageRule((string) $p['siteId'], $req->body), 201), auth: true);
$router->add('GET', '/api/v1/sites/{siteId}/page-rules', static fn (Request $req, array $p) => Response::json($rulesController->listPageRules((string) $p['siteId'])), auth: true);
$router->add('PATCH', '/api/v1/sites/{siteId}/page-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->updatePageRule((string) $p['siteId'], (string) $p['ruleId'], $req->body)), auth: true);
$router->add('DELETE', '/api/v1/sites/{siteId}/page-rules/{ruleId}', static fn (Request $req, array $p) => Response::json($rulesController->deletePageRule((string) $p['siteId'], (string) $p['ruleId'])), auth: true);
$router->add('POST', '/api/v1/sites/{siteId}/page-rules/test', static fn (Request $req, array $p) => Response::json($rulesController->testPageRule((string) $p['siteId'], $req->body)), auth: true);

$router->add('GET', '/api/v1/edge/nodes', static fn () => Response::json($edgeController->list()), auth: true);
$router->add('POST', '/api/v1/edge/register', static fn (Request $req) => Response::json($edgeController->register($req->body)), edgeAuth: true);
$router->add('POST', '/api/v1/edge/heartbeat', static fn (Request $req) => Response::json($edgeController->heartbeat($req->body)), edgeAuth: true);
$router->add('GET', '/api/v1/edge/config', static fn (Request $req) => Response::json($configService->buildSnapshotForVersion(isset($req->query['if_version']) ? (int) $req->query['if_version'] : null)), edgeAuth: true);
$router->add('POST', '/api/v1/collector/usage', static fn (Request $req) => Response::json($collectorController->ingest($req->body)), edgeAuth: true);
$router->add('GET', '/api/v1/usage/summary', static fn (Request $req) => Response::json($collectorController->summary(isset($req->query['site_id']) ? (string) $req->query['site_id'] : null, isset($req->query['bucket']) ? (string) $req->query['bucket'] : null)), auth: true);
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
respond($result['payload'], (int) $result['status']);
