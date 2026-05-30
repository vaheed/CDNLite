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
use App\Modules\Sites\Http\Controllers\SiteController;
use App\Modules\Sites\Services\SiteService;

header('Content-Type: application/json');

function respond(array $payload, int $defaultStatus = 200): void
{
    $status = isset($payload['status']) ? (int) $payload['status'] : $defaultStatus;
    unset($payload['status']);
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

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$bodyRaw = file_get_contents('php://input');
$body = $bodyRaw ? json_decode($bodyRaw, true) : [];
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
$edgeAuth = new EdgeAuthService();

if ($method === 'GET' && $path === '/health') {
    respond(['ok' => true, 'time' => time()]);
}

if ($method === 'GET' && $path === '/api/v1/sites') {
    respond($siteController->index());
}

if ($method === 'POST' && $path === '/api/v1/sites') {
    respond($siteController->store($body), 201);
}

if ($method === 'PATCH' && preg_match('#^/api/v1/sites/(\d+)$#', $path, $m)) {
    $result = $siteController->update((int) $m[1], $body);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/(\d+)$#', $path, $m)) {
    respond($siteController->delete((int) $m[1]));
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/(\d+)/proxy/enable$#', $path, $m)) {
    $result = $siteController->enableProxy((int) $m[1]);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/(\d+)/proxy/disable$#', $path, $m)) {
    $result = $siteController->disableProxy((int) $m[1]);
    if ($result === null) {
        respond(['error' => 'site_not_found'], 404);
    }
    respond($result);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/(\d+)/dns/records$#', $path, $m)) {
    respond($dnsController->create((int) $m[1], $body), 201);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/(\d+)/dns/records$#', $path, $m)) {
    respond($dnsController->list((int) $m[1]));
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/(\d+)/dns/records/(\d+)$#', $path, $m)) {
    respond($dnsController->delete((int) $m[1], (int) $m[2]));
}

if ($method === 'GET' && $path === '/api/v1/edge/nodes') {
    respond($edgeController->list());
}

if ($method === 'POST' && $path === '/api/v1/edge/register') {
    $edgeIdHeader = headerValue('X-CDNT-Edge-Id') ?? '';
    if ($edgeIdHeader === '' || $edgeIdHeader !== (string) ($body['edge_id'] ?? '')) {
        respond(['error' => 'edge_auth_edge_id_mismatch'], 401);
    }
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNT-Timestamp') ?? 0),
        (string) (headerValue('X-CDNT-Nonce') ?? '')
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($edgeController->register($body));
}

if ($method === 'POST' && $path === '/api/v1/edge/heartbeat') {
    $edgeIdHeader = headerValue('X-CDNT-Edge-Id') ?? '';
    if ($edgeIdHeader === '' || $edgeIdHeader !== (string) ($body['edge_id'] ?? '')) {
        respond(['error' => 'edge_auth_edge_id_mismatch'], 401);
    }
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNT-Timestamp') ?? 0),
        (string) (headerValue('X-CDNT-Nonce') ?? '')
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($edgeController->heartbeat($body));
}

if ($method === 'GET' && $path === '/api/v1/edge/config') {
    $edgeIdHeader = headerValue('X-CDNT-Edge-Id') ?? '';
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNT-Timestamp') ?? 0),
        (string) (headerValue('X-CDNT-Nonce') ?? '')
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    $ifVersion = isset($_GET['if_version']) ? (int) $_GET['if_version'] : null;
    respond($configService->buildSnapshotForVersion($ifVersion));
}

if ($method === 'POST' && $path === '/api/v1/collector/usage') {
    $edgeIdHeader = headerValue('X-CDNT-Edge-Id') ?? '';
    $auth = $edgeAuth->authenticate(
        $edgeIdHeader,
        bearerToken(),
        (int) (headerValue('X-CDNT-Timestamp') ?? 0),
        (string) (headerValue('X-CDNT-Nonce') ?? '')
    );
    if (($auth['ok'] ?? false) !== true) {
        respond(['error' => (string) $auth['error']], (int) $auth['status']);
    }
    respond($collectorController->ingest($body));
}

if ($method === 'GET' && $path === '/api/v1/usage/summary') {
    $siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : null;
    respond($collectorController->summary($siteId));
}

respond(['error' => 'not_found'], 404);
