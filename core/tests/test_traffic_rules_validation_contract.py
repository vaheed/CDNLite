import json
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def run_php(script: str) -> dict:
    proc = subprocess.run(["php", "-r", script], cwd=str(REPO_ROOT), capture_output=True, text=True, check=True)
    return json.loads(proc.stdout)


def test_traffic_rules_controller_validation_contract():
    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$c = new App\Modules\Proxy\Http\Controllers\TrafficRulesController(new App\Modules\Proxy\Services\TrafficRulesService());

$badRedirect = $c->createRedirect('site-1', ['source_path' => 'no-slash', 'target_url' => 'https://example.com']);
$badWaf = $c->createWaf('site-1', ['type' => 'ip_cidr', 'pattern' => '1.2.3.4/32']);
$badCache = $c->createCacheRule('site-1', ['path_prefix' => 'assets', 'ttl_seconds' => 3600]);
$badRate = $c->setRateLimit('site-1', ['requests_per_minute' => 0]);
$badWafPatch = $c->updateWaf('site-1', 'rule-1', ['type' => 'country_is']);
$badCachePatch = $c->updateCacheRule('site-1', 'rule-2', ['ttl_seconds' => 0]);
$badRedirectMatchType = $c->createRedirect('site-1', ['source_path' => '/old', 'target_url' => 'https://example.com', 'match_type' => 'regex']);
$badRedirectPriority = $c->updateRedirect('site-1', 'rule-9', ['priority' => 0]);
$badPageRule = $c->createPageRule('site-1', ['pattern' => 'admin/*', 'actions' => ['cache' => 'bypass']]);

echo json_encode([
  'badRedirect' => $badRedirect,
  'badWaf' => $badWaf,
  'badCache' => $badCache,
  'badRate' => $badRate,
  'badWafPatch' => $badWafPatch,
  'badCachePatch' => $badCachePatch,
  'badRedirectMatchType' => $badRedirectMatchType,
  'badRedirectPriority' => $badRedirectPriority,
  'badPageRule' => $badPageRule,
], JSON_UNESCAPED_SLASHES);
'''
    out = run_php(script)

    assert out['badRedirect']['error'] == 'invalid_field'
    assert out['badRedirect']['field'] == 'source_path'

    assert out['badWaf']['error'] == 'invalid_field'
    assert out['badWaf']['field'] == 'type'

    assert out['badCache']['error'] == 'invalid_field'
    assert out['badCache']['field'] == 'path_prefix'

    assert out['badRate']['error'] == 'invalid_field'
    assert out['badRate']['field'] == 'requests_per_minute'

    assert out['badWafPatch']['error'] == 'invalid_field'
    assert out['badWafPatch']['field'] == 'type'

    assert out['badCachePatch']['error'] == 'invalid_field'
    assert out['badCachePatch']['field'] == 'ttl_seconds'

    assert out['badRedirectMatchType']['error'] == 'invalid_field'
    assert out['badRedirectMatchType']['field'] == 'match_type'

    assert out['badRedirectPriority']['error'] == 'invalid_field'
    assert out['badRedirectPriority']['field'] == 'priority'

    assert out['badPageRule']['error'] == 'invalid_field'
    assert out['badPageRule']['field'] == 'pattern'
