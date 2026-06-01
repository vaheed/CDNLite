<?php

require __DIR__ . '/app/Support/bootstrap.php';

use App\Modules\Collector\Http\Controllers\CollectorController;
use App\Modules\Collector\Services\CollectorService;
use App\Modules\Dns\Http\Controllers\DnsController;
use App\Modules\Dns\Services\DnsService;
use App\Modules\Edge\Http\Controllers\EdgeController;
use App\Modules\Edge\Services\EdgeAuthService;
use App\Modules\Edge\Services\EdgeService;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Proxy\Http\Controllers\TrafficRulesController;
use App\Modules\Sites\Http\Controllers\SiteController;
use App\Modules\Sites\Services\SiteService;
use App\Support\ApiAuth;
use App\Support\Logger;

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
if (!is_array($body)) {
    $body = [];
}

$siteService = new SiteService();
$dnsService = new DnsService();
$siteController = new SiteController($siteService);
$dnsController = new DnsController($dnsService);
$edgeController = new EdgeController(new EdgeService());
$collectorController = new CollectorController(new CollectorService());
$configService = new ConfigService($siteService, $dnsService);
$rulesController = new TrafficRulesController(new TrafficRulesService());
$edgeAuth = new EdgeAuthService();

if ($method === 'GET' && $path === '/health') {
    respond(['ok' => true, 'time' => time()]);
}

if ($method === 'GET' && $path === '/ready') {
    $checks = ['postgres' => 'ok', 'schema' => 'ok', 'config_generation' => 'ok'];
    try {
        \App\Support\Database::pdo()->query('SELECT 1');
    } catch (\Throwable) {
        $checks['postgres'] = 'fail';
    }
    try {
        $required = ['sites','redirect_rules','rate_limit_rules','waf_rules','cache_rules','config_state','config_snapshots'];
        foreach ($required as $table) {
            $stmt = \App\Support\Database::pdo()->query("SELECT to_regclass('public." . $table . "')");
            if ($stmt->fetchColumn() === null) { $checks['schema'] = 'fail'; break; }
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
    respond(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503);
}

if ($method === 'GET' && $path === '/api/v1/sites') {
    requireApiAuth();
    respond($siteController->index());
}

if ($method === 'POST' && $path === '/api/v1/sites') {
    requireApiAuth();
    respond($siteController->store($body), 201);
}

if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)$#', $path, $m)) {
    requireApiAuth();
    $result = $siteController->update((string) $m[1], $body);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)$#', $path, $m)) {
    requireApiAuth();
    respond($siteController->delete((string) $m[1]));
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/proxy/enable$#', $path, $m)) {
    requireApiAuth();
    $result = $siteController->enableProxy((string) $m[1]);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/proxy/disable$#', $path, $m)) {
    requireApiAuth();
    $result = $siteController->disableProxy((string) $m[1]);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/dns/records$#', $path, $m)) {
    requireApiAuth();
    respond($dnsController->create((string) $m[1], $body), 201);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/dns/records$#', $path, $m)) {
    requireApiAuth();
    respond($dnsController->list((string) $m[1]));
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/redirects$#', $path, $m)) { requireApiAuth(); respond($rulesController->createRedirect((string) $m[1], $body), 201); }
if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/redirects$#', $path, $m)) { requireApiAuth(); respond($rulesController->listRedirects((string) $m[1])); }
if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/redirects/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->updateRedirect((string) $m[1], (string) $m[2], $body)); }
if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/redirects/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->deleteRedirect((string) $m[1], (string) $m[2])); }

if ($method === 'PUT' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/rate-limit$#', $path, $m)) { requireApiAuth(); respond($rulesController->setRateLimit((string) $m[1], $body)); }
if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/rate-limit$#', $path, $m)) { requireApiAuth(); respond($rulesController->getRateLimit((string) $m[1])); }
if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/rate-limit$#', $path, $m)) { requireApiAuth(); respond($rulesController->disableRateLimit((string) $m[1])); }

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/waf-rules$#', $path, $m)) { requireApiAuth(); respond($rulesController->createWaf((string) $m[1], $body), 201); }
if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/waf-rules$#', $path, $m)) { requireApiAuth(); respond($rulesController->listWaf((string) $m[1])); }
if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/waf-rules/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->updateWaf((string) $m[1], (string) $m[2], $body)); }
if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/waf-rules/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->deleteWaf((string) $m[1], (string) $m[2])); }

if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/cache-rules$#', $path, $m)) { requireApiAuth(); respond($rulesController->createCacheRule((string) $m[1], $body), 201); }
if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/cache-rules$#', $path, $m)) { requireApiAuth(); respond($rulesController->listCacheRules((string) $m[1])); }
if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/cache-rules/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->updateCacheRule((string) $m[1], (string) $m[2], $body)); }
if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/cache-rules/([0-9a-fA-F-]+)$#', $path, $m)) { requireApiAuth(); respond($rulesController->deleteCacheRule((string) $m[1], (string) $m[2])); }

if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/dns/records/([0-9a-fA-F-]+)$#', $path, $m)) {
    requireApiAuth();
    respond($dnsController->update((string) $m[1], (string) $m[2], $body));
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/dns/records/([0-9a-fA-F-]+)$#', $path, $m)) {
    requireApiAuth();
    respond($dnsController->delete((string) $m[1], (string) $m[2]));
}

if ($method === 'GET' && $path === '/api/v1/edge/nodes') {
    requireApiAuth();
    respond($edgeController->list());
}

if ($method === 'POST' && $path === '/api/v1/edge/register') {
    $edgeIdHeader = headerValue('X-CDNLITE-Edge-Id') ?? '';
    if ($edgeIdHeader === '' || $edgeIdHeader !== (string) ($body['edge_id'] ?? '')) {
        respond(['error' => 'edge_auth_edge_id_mismatch'], 401);
    }
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNLITE-Timestamp') ?? 0),
        (string) (headerValue('X-CDNLITE-Nonce') ?? ''),
        $method,
        $path,
        $bodyRaw ?: '',
        edgeSignature()
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($edgeController->register($body));
}

if ($method === 'POST' && $path === '/api/v1/edge/heartbeat') {
    $edgeIdHeader = headerValue('X-CDNLITE-Edge-Id') ?? '';
    if ($edgeIdHeader === '' || $edgeIdHeader !== (string) ($body['edge_id'] ?? '')) {
        respond(['error' => 'edge_auth_edge_id_mismatch'], 401);
    }
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNLITE-Timestamp') ?? 0),
        (string) (headerValue('X-CDNLITE-Nonce') ?? ''),
        $method,
        $path,
        $bodyRaw ?: '',
        edgeSignature()
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($edgeController->heartbeat($body));
}

if ($method === 'GET' && $path === '/api/v1/edge/config') {
    $edgeIdHeader = headerValue('X-CDNLITE-Edge-Id') ?? '';
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNLITE-Timestamp') ?? 0),
        (string) (headerValue('X-CDNLITE-Nonce') ?? ''),
        $method,
        $path,
        '',
        edgeSignature()
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    $ifVersion = isset($_GET['if_version']) ? (int) $_GET['if_version'] : null;
    respond($configService->buildSnapshotForVersion($ifVersion));
}

if ($method === 'POST' && $path === '/api/v1/collector/usage') {
    $edgeIdHeader = headerValue('X-CDNLITE-Edge-Id') ?? '';
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNLITE-Timestamp') ?? 0),
        (string) (headerValue('X-CDNLITE-Nonce') ?? ''),
        $method,
        $path,
        $bodyRaw ?: '',
        edgeSignature()
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($collectorController->ingest($body));
}

if ($method === 'GET' && $path === '/api/v1/usage/summary') {
    requireApiAuth();
    $siteId = isset($_GET['site_id']) ? (string) $_GET['site_id'] : null;
    $bucket = isset($_GET['bucket']) ? (string) $_GET['bucket'] : null;
    respond($collectorController->summary($siteId, $bucket));
}

if ($method === 'POST' && $path === '/api/v1/usage/recalculate') {
    requireApiAuth();
    respond($collectorController->recalculate($body));
}

respond(['error' => 'not_found'], 404);
