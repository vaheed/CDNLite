<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Modules\Collector\Services\CollectorService;
use App\Modules\Edge\Services\EdgeService;
use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Sites\Services\SiteService;
use App\Support\Request;

class DashboardController
{
    /** @var array<int,array<string,string>> */
    private array $consolePresets = [
        ['name' => 'List Sites', 'method' => 'GET', 'path' => '/api/v1/sites', 'body' => '{}'],
        ['name' => 'Create Site', 'method' => 'POST', 'path' => '/api/v1/sites', 'body' => '{"name":"Demo","domain":"demo.local","origin_host":"core","origin_port":8080}'],
        ['name' => 'List Edges', 'method' => 'GET', 'path' => '/api/v1/edge/nodes', 'body' => '{}'],
        ['name' => 'Usage Summary', 'method' => 'GET', 'path' => '/api/v1/usage/summary', 'body' => '{}'],
    ];
    public function __construct(
        private SiteService $sites,
        private EdgeService $edges,
        private CollectorService $collector,
        private TrafficRulesService $rules,
    ) {
    }

    public function sitesPage(): array
    {
        $sites = $this->sites->all();
        $edgeCount = count($this->edges->list());
        $cards = '';
        foreach ($sites as $site) {
            $status = $site['proxy_enabled'] ? 'Proxy On' : 'Proxy Off';
            $cards .= '<a class="site-card" href="/dashboard/sites/' . htmlspecialchars($site['id']) . '"><h3>' . htmlspecialchars($site['name']) . '</h3><p>' . htmlspecialchars($site['domain']) . '</p><span>' . $status . '</span></a>';
        }
        if ($cards === '') {
            $cards = '<div class="empty">No sites yet. Create one using API or CLI, then refresh.</div>';
        }
        return ['html' => $this->layout('Sites', '<section class="hero"><h1>CDNLite Control Deck</h1><p>Operate your websites with realtime signals and quick drill-down.</p><div class="kpi"><b>' . count($sites) . '</b><span>Sites</span></div><div class="kpi"><b>' . $edgeCount . '</b><span>Edges</span></div></section><section class="grid">' . $cards . '</section>')];
    }

    public function sitePage(string $siteId, ?string $flash = null): array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            return ['html' => $this->layout('Site Missing', '<div class="empty">Site not found.</div>'), 'status' => 404];
        }
        $cache = $this->collector->cacheAnalytics($siteId);
        $purges = array_slice($this->rules->listCachePurgeRequests($siteId), 0, 6);
        $events = array_slice($this->rules->listSecurityEvents($siteId, null, 6), 0, 6);
        $ssl = array_slice($this->rules->listSslCertificates($siteId), 0, 6);

        $purgeRows = '';
        foreach ($purges as $row) {
            $purgeRows .= '<tr><td>' . htmlspecialchars((string) $row['type']) . '</td><td>' . htmlspecialchars((string) ($row['value'] ?? '*')) . '</td><td>' . htmlspecialchars((string) $row['status']) . '</td></tr>';
        }
        $eventRows = '';
        foreach ($events as $row) {
            $eventRows .= '<tr><td>' . htmlspecialchars((string) $row['type']) . '</td><td>' . htmlspecialchars((string) ($row['details']['decision'] ?? '-')) . '</td><td>' . date('Y-m-d H:i', (int) $row['created_at']) . '</td></tr>';
        }
        $sslRows = '';
        foreach ($ssl as $row) {
            $sslRows .= '<tr><td>' . htmlspecialchars((string) $row['hostname']) . '</td><td>' . htmlspecialchars((string) $row['status']) . '</td><td>' . htmlspecialchars((string) ($row['days_until_expiry'] ?? '-')) . '</td></tr>';
        }

        $flashHtml = $flash !== null ? '<div class="flash">' . htmlspecialchars($flash) . '</div>' : '';
        $content = '<section class="hero"><h1>' . htmlspecialchars($site['name']) . '</h1><p>' . htmlspecialchars($site['domain']) . '</p></section>' . $flashHtml
            . '<section class="stats">'
            . '<article><h4>Hit Ratio</h4><b>' . round(((float) $cache['hit_ratio']) * 100, 2) . '%</b></article>'
            . '<article><h4>Requests</h4><b>' . (int) $cache['requests'] . '</b></article>'
            . '<article><h4>Cache HIT</h4><b>' . (int) $cache['hit'] . '</b></article>'
            . '<article><h4>Cache MISS</h4><b>' . (int) $cache['miss'] . '</b></article>'
            . '</section>'
            . '<section class="panel"><h3>Quick Actions</h3>'
            . '<form method="post" action="/dashboard/sites/' . htmlspecialchars($siteId) . '/proxy"><input type="hidden" name="action" value="' . ($site['proxy_enabled'] ? 'disable' : 'enable') . '"><button type="submit">' . ($site['proxy_enabled'] ? 'Disable Proxy' : 'Enable Proxy') . '</button></form>'
            . '<form method="post" action="/dashboard/sites/' . htmlspecialchars($siteId) . '/purge"><label>Purge Type</label><select name="type"><option value="everything">everything</option><option value="site">site</option><option value="prefix">prefix</option><option value="url">url</option></select><label>Value</label><input name="value" placeholder="/images or https://..."><button type="submit">Create Purge</button></form>'
            . '<form method="post" action="/dashboard/sites/' . htmlspecialchars($siteId) . '/waf"><label>WAF Type</label><select name="type"><option value="path_contains">path_contains</option><option value="path_prefix">path_prefix</option><option value="user_agent_contains">user_agent_contains</option><option value="ip_cidr">ip_cidr</option><option value="country_is">country_is</option><option value="method_is">method_is</option><option value="header_contains">header_contains</option></select><label>Pattern</label><input name="pattern" placeholder="e.g. /wp-admin"><button type="submit">Add WAF Rule</button></form>'
            . '</section>'
            . '<section class="panel"><h3>Recent Purges</h3><table><tr><th>Type</th><th>Value</th><th>Status</th></tr>' . $purgeRows . '</table></section>'
            . '<section class="panel"><h3>Security Events</h3><table><tr><th>Type</th><th>Action</th><th>Time</th></tr>' . $eventRows . '</table></section>'
            . '<section class="panel"><h3>SSL Status</h3><table><tr><th>Hostname</th><th>Status</th><th>Days Left</th></tr>' . $sslRows . '</table></section>';
        return ['html' => $this->layout('Site Detail', $content)];
    }

    public function proxyAction(Request $req, string $siteId): array
    {
        $action = (string) ($req->body['action'] ?? '');
        $site = $action === 'disable' ? $this->sites->setProxy($siteId, false) : $this->sites->setProxy($siteId, true);
        $msg = $site === null ? 'Site not found.' : ($action === 'disable' ? 'Proxy disabled.' : 'Proxy enabled.');
        return $this->sitePage($siteId, $msg);
    }

    public function purgeAction(Request $req, string $siteId): array
    {
        $type = (string) ($req->body['type'] ?? 'everything');
        $value = trim((string) ($req->body['value'] ?? ''));
        $payload = ['type' => $type];
        if ($value !== '') {
            $payload['value'] = $value;
        }
        $this->rules->createCachePurgeRequest($siteId, $payload);
        return $this->sitePage($siteId, 'Purge request created.');
    }

    public function wafAction(Request $req, string $siteId): array
    {
        $type = (string) ($req->body['type'] ?? 'path_contains');
        $pattern = trim((string) ($req->body['pattern'] ?? ''));
        if ($pattern !== '') {
            $this->rules->createWaf($siteId, ['enabled' => true, 'type' => $type, 'pattern' => $pattern, 'action' => 'block']);
            return $this->sitePage($siteId, 'WAF rule created.');
        }
        return $this->sitePage($siteId, 'Pattern is required.');
    }

    public function consolePage(?string $response = null): array
    {
        $opts = '';
        foreach ($this->consolePresets as $preset) {
            $opts .= '<option value="' . htmlspecialchars(json_encode($preset, JSON_UNESCAPED_SLASHES) ?: '{}') . '">' . htmlspecialchars($preset['name']) . '</option>';
        }
        $content = '<section class="hero"><h1>API Action Console</h1><p>Run any control-plane action from dashboard.</p></section>'
            . '<section class="panel"><form method="post" action="/dashboard/console/run">'
            . '<label>Preset</label><select id="preset" onchange="loadPreset(this.value)"><option value="">Custom...</option>' . $opts . '</select>'
            . '<label>Method</label><select id="method" name="method"><option>GET</option><option>POST</option><option>PATCH</option><option>PUT</option><option>DELETE</option></select>'
            . '<label>Path</label><input id="path" name="path" placeholder="/api/v1/sites">'
            . '<label>JSON Body</label><textarea id="body" name="body" rows="10" style="width:100%;border:1px solid #cfd9dc;border-radius:10px;padding:10px;font-family:monospace">{}</textarea>'
            . '<button type="submit">Run Action</button></form></section>';
        if ($response !== null) {
            $content .= '<section class="panel"><h3>Response</h3><pre style="white-space:pre-wrap;background:#0f1720;color:#d6e7ff;padding:12px;border-radius:12px;overflow:auto">' . htmlspecialchars($response) . '</pre></section>';
        }
        $content .= '<script>function loadPreset(v){if(!v)return;try{var p=JSON.parse(v);document.getElementById("method").value=p.method||"GET";document.getElementById("path").value=p.path||"";document.getElementById("body").value=p.body||"{}";}catch(e){}}</script>';
        return ['html' => $this->layout('API Console', $content)];
    }

    public function opsPage(): array
    {
        $sites = $this->sites->all();
        $edges = $this->edges->list();
        $security = [];
        $sslRisk = [];
        $purgeRecent = [];
        $cacheLow = [];

        foreach ($sites as $site) {
            $siteId = (string) $site['id'];
            $events = $this->rules->listSecurityEvents($siteId, null, 8);
            foreach ($events as $event) {
                $security[] = ['site' => (string) $site['domain'], 'type' => (string) $event['type'], 'decision' => (string) ($event['details']['decision'] ?? '-'), 'at' => (int) $event['created_at']];
            }
            $certs = $this->rules->listSslCertificates($siteId);
            foreach ($certs as $cert) {
                if ((string) ($cert['status'] ?? '') !== 'active' || ((int) ($cert['days_until_expiry'] ?? 9999)) <= 30) {
                    $sslRisk[] = ['site' => (string) $site['domain'], 'host' => (string) ($cert['hostname'] ?? ''), 'status' => (string) ($cert['status'] ?? ''), 'days' => (string) ($cert['days_until_expiry'] ?? '-')];
                }
            }
            $cache = $this->collector->cacheAnalytics($siteId);
            if ((float) ($cache['hit_ratio'] ?? 0.0) < 0.3 && (int) ($cache['requests'] ?? 0) > 20) {
                $cacheLow[] = ['site' => (string) $site['domain'], 'ratio' => round(((float) $cache['hit_ratio']) * 100, 2), 'requests' => (int) $cache['requests']];
            }
            foreach (array_slice($this->rules->listCachePurgeRequests($siteId), 0, 2) as $pr) {
                $purgeRecent[] = ['site' => (string) $site['domain'], 'type' => (string) $pr['type'], 'status' => (string) $pr['status'], 'at' => (int) ($pr['created_at'] ?? 0)];
            }
        }
        usort($security, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        usort($purgeRecent, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

        $edgeRows = '';
        foreach ($edges as $e) {
            $stale = (time() - (int) ($e['last_heartbeat'] ?? 0)) > 120 ? 'stale' : 'ok';
            $edgeRows .= '<tr><td>' . htmlspecialchars((string) $e['edge_id']) . '</td><td>' . htmlspecialchars((string) ($e['region'] ?? '-')) . '</td><td>' . htmlspecialchars((string) ($e['public_ip'] ?? '-')) . '</td><td>' . $stale . '</td></tr>';
        }
        $secRows = '';
        foreach (array_slice($security, 0, 15) as $row) {
            $secRows .= '<tr><td>' . htmlspecialchars($row['site']) . '</td><td>' . htmlspecialchars($row['type']) . '</td><td>' . htmlspecialchars($row['decision']) . '</td><td>' . date('Y-m-d H:i:s', $row['at']) . '</td></tr>';
        }
        $sslRows = '';
        foreach (array_slice($sslRisk, 0, 15) as $row) {
            $sslRows .= '<tr><td>' . htmlspecialchars($row['site']) . '</td><td>' . htmlspecialchars($row['host']) . '</td><td>' . htmlspecialchars($row['status']) . '</td><td>' . htmlspecialchars($row['days']) . '</td></tr>';
        }
        $purgeRows = '';
        foreach (array_slice($purgeRecent, 0, 15) as $row) {
            $purgeRows .= '<tr><td>' . htmlspecialchars($row['site']) . '</td><td>' . htmlspecialchars($row['type']) . '</td><td>' . htmlspecialchars($row['status']) . '</td><td>' . date('Y-m-d H:i:s', $row['at']) . '</td></tr>';
        }
        $cacheRows = '';
        foreach (array_slice($cacheLow, 0, 15) as $row) {
            $cacheRows .= '<tr><td>' . htmlspecialchars($row['site']) . '</td><td>' . htmlspecialchars((string) $row['ratio']) . '%</td><td>' . htmlspecialchars((string) $row['requests']) . '</td></tr>';
        }

        $content = '<section class="hero"><h1>Ops & Troubleshooting</h1><p>High-signal runtime diagnostics for fast triage.</p></section>'
            . '<section class="stats">'
            . '<article><h4>Sites</h4><b>' . count($sites) . '</b></article>'
            . '<article><h4>Edges</h4><b>' . count($edges) . '</b></article>'
            . '<article><h4>Security Events (recent)</h4><b>' . count($security) . '</b></article>'
            . '<article><h4>SSL Risks</h4><b>' . count($sslRisk) . '</b></article>'
            . '</section>'
            . '<section class="panel"><h3>Edge Health</h3><table><tr><th>Edge</th><th>Region</th><th>IP</th><th>Heartbeat</th></tr>' . $edgeRows . '</table></section>'
            . '<section class="panel"><h3>Recent Security Events</h3><table><tr><th>Site</th><th>Type</th><th>Decision</th><th>Time</th></tr>' . $secRows . '</table></section>'
            . '<section class="panel"><h3>SSL Risk View</h3><table><tr><th>Site</th><th>Hostname</th><th>Status</th><th>Days Left</th></tr>' . $sslRows . '</table></section>'
            . '<section class="panel"><h3>Purge Timeline</h3><table><tr><th>Site</th><th>Type</th><th>Status</th><th>Time</th></tr>' . $purgeRows . '</table></section>'
            . '<section class="panel"><h3>Low Cache Efficiency Sites</h3><table><tr><th>Site</th><th>Hit Ratio</th><th>Requests</th></tr>' . $cacheRows . '</table></section>';
        return ['html' => $this->layout('Ops', $content)];
    }

    public function consoleRun(Request $req): array
    {
        $method = strtoupper(trim((string) ($req->body['method'] ?? 'GET')));
        $path = trim((string) ($req->body['path'] ?? ''));
        $bodyRaw = trim((string) ($req->body['body'] ?? '{}'));
        if ($path === '' || !str_starts_with($path, '/api/v1/')) {
            return $this->consolePage("Only /api/v1/* paths are allowed.");
        }
        $payload = [];
        if ($bodyRaw !== '') {
            $decoded = json_decode($bodyRaw, true);
            if (!is_array($decoded)) {
                return $this->consolePage("Invalid JSON body.");
            }
            $payload = $decoded;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
        $url = $scheme . '://' . $host . $path;

        $headers = ['Content-Type: application/json'];
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($auth !== '') {
            $headers[] = 'Authorization: ' . $auth;
        }

        $raw = false;
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if (!in_array($method, ['GET', 'DELETE'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                $fallback = $this->runInProcess($method, $path, $payload);
                if ($fallback !== null) {
                    return $this->consolePage("HTTP 200\n\n" . json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                return $this->consolePage("Request failed: " . $err);
            }
        } else {
            $opts = [
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ];
            if (!in_array($method, ['GET', 'DELETE'], true)) {
                $opts['http']['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
            }
            $ctx = stream_context_create($opts);
            $raw = @file_get_contents($url, false, $ctx);
            $metaHeaders = $http_response_header ?? [];
            foreach ($metaHeaders as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
        if ($raw === false) {
            $fallback = $this->runInProcess($method, $path, $payload);
            if ($fallback !== null) {
                return $this->consolePage("HTTP 200\n\n" . json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            return $this->consolePage('Request failed: transport_error');
        }
        }
        $pretty = $raw;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;
        }
        return $this->consolePage("HTTP " . $status . "\n\n" . $pretty);
    }

    private function runInProcess(string $method, string $path, array $payload): ?array
    {
        if ($method === 'POST' && $path === '/api/v1/sites') {
            return ['data' => $this->sites->create($payload)];
        }
        if ($method === 'PATCH' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)$#', $path, $m) === 1) {
            $updated = $this->sites->update((string) $m[1], $payload);
            return $updated !== null ? ['data' => $updated] : ['error' => 'site_not_found'];
        }
        if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)$#', $path, $m) === 1) {
            return $this->sites->delete((string) $m[1]) ? ['ok' => true] : ['error' => 'site_not_found'];
        }
        if ($method === 'POST' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/proxy/(enable|disable)$#', $path, $m) === 1) {
            $site = $this->sites->setProxy((string) $m[1], $m[2] === 'enable');
            return $site !== null ? ['data' => $site] : ['error' => 'site_not_found'];
        }
        if ($method === 'GET' && preg_match('#^/api/v1/sites/([0-9a-fA-F-]+)/security/events(?:\?(.*))?$#', $path, $m) === 1) {
            $siteId = (string) $m[1];
            parse_str((string) ($m[2] ?? ''), $query);
            $type = isset($query['type']) && is_string($query['type']) ? trim($query['type']) : null;
            $limit = isset($query['limit']) && is_scalar($query['limit']) ? (int) $query['limit'] : 100;
            return ['data' => $this->rules->listSecurityEvents($siteId, $type, $limit)];
        }
        if ($method === 'GET' && $path === '/api/v1/sites') {
            return ['data' => $this->sites->all()];
        }
        if ($method === 'GET' && $path === '/api/v1/edge/nodes') {
            return ['data' => $this->edges->list()];
        }
        if ($method === 'GET' && $path === '/api/v1/usage/summary') {
            return ['data' => $this->collector->summary()];
        }
        return null;
    }

    private function layout(string $title, string $content): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title><style>
        :root{--bg:#f4f7f8;--ink:#12252b;--card:#ffffff;--accent:#0f9d8a;--muted:#5b7075}
        body{margin:0;font-family:"IBM Plex Sans",system-ui,sans-serif;background:radial-gradient(circle at 15% 10%,#dff5ef 0,#f4f7f8 40%);color:var(--ink)}
        .wrap{max-width:1100px;margin:0 auto;padding:24px}
        nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
        nav a{color:var(--ink);text-decoration:none;font-weight:700}
        .hero{background:linear-gradient(120deg,#11353d,#195364);color:#fff;padding:24px;border-radius:18px}
        .hero p{opacity:.9}
        .kpi{display:inline-flex;gap:8px;align-items:baseline;margin-right:16px}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:16px}
        .site-card,.panel,article{background:var(--card);border-radius:16px;padding:16px;box-shadow:0 10px 28px rgba(0,0,0,.08)}
        .site-card{display:block;color:inherit;text-decoration:none;border:1px solid #e5ecee}
        .site-card:hover{transform:translateY(-2px);transition:.2s ease}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:14px 0}
        table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #e5ecee;text-align:left;font-size:14px}
        .panel{margin:12px 0}
        .panel form{display:grid;gap:8px;margin:10px 0;padding:10px;border:1px solid #e5ecee;border-radius:12px}
        input,select,button{padding:10px;border-radius:10px;border:1px solid #cfd9dc}
        button{background:#0f9d8a;color:#fff;border:none;font-weight:700;cursor:pointer}
        .flash{background:#e8fff8;border:1px solid #b6f0de;color:#115749;padding:12px;border-radius:12px;margin:12px 0}
        .empty{background:#fff1f1;padding:16px;border-radius:12px;color:#8b2f2f}
        @media(max-width:700px){.wrap{padding:12px}.hero{padding:18px}}
        </style></head><body><div class="wrap"><nav><a href="/dashboard/sites">CDNLite Dashboard</a><span><a href="/dashboard/ops">Ops</a> <a href="/dashboard/console">Console</a> <a href="/api/v1/sites">API</a></span></nav>' . $content . '</div></body></html>';
    }
}
