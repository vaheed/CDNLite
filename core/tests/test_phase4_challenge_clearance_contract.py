from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_edge_challenge_issues_signed_scoped_clearance_cookie():
    clearance = read("edge/openresty/lua/clearance.lua")
    router = read("edge/openresty/lua/router.lua")
    nginx = read("edge/openresty/nginx.conf")
    compose = read("docker-compose.yml")

    assert "ngx.hmac_sha1" in clearance
    assert "__cdnlite_clearance" in clearance
    assert "HttpOnly; SameSite=Lax" in clearance
    assert "CDNLITE_EDGE_CLEARANCE_SECRET" in clearance
    assert "function M.issue(domain_id, action, rule_id, client_ip, ttl)" in clearance
    assert "function M.consume_challenge(domain_id, action, rule_id, client_ip)" in clearance
    assert "function M.has_clearance(domain_id, action, rule_id, client_ip)" in clearance
    assert "signed_clearance" in clearance
    assert "scope_matches(claims, domain_id, action, rule_id, client_ip)" in clearance

    assert "env CDNLITE_EDGE_CLEARANCE_SECRET;" in nginx
    assert "CDNLITE_EDGE_CLEARANCE_SECRET" in compose
    assert "local clearance = require('clearance')" in router


def test_waf_and_rate_limit_challenges_are_verifiable_not_static_denies():
    router = read("edge/openresty/lua/router.lua")

    assert "clearance.has_clearance(domain.domain_id, 'waf', rule.id, client_ip)" in router
    assert "clearance.consume_challenge(domain.domain_id, 'waf', rule.id, client_ip)" in router
    assert "clearance.challenge_response(domain.domain_id, 'waf', rule.id, client_ip, 403, 'bot_challenge_required')" in router
    assert "clearance.has_clearance(domain_id, 'rate_limit', rule.id, client_ip)" in router
    assert "clearance.consume_challenge(domain_id, 'rate_limit', rule.id, client_ip)" in router
    assert "clearance.challenge_response(domain_id, 'rate_limit', rule.id, client_ip, 429, 'challenge_required')" in router
    assert "return ngx.exit(303)" in router

    block_index = router.index("if ngx.ctx.security_action == 'block' then")
    challenge_index = router.index("if ngx.ctx.security_action == 'challenge' then")
    assert block_index < challenge_index


def test_phase4_runner_and_stress_registration_are_present():
    phase = read("ci/phase.sh")
    manifest = read("ci/phases/phase-04.yml")
    stress = read("ci/stress-platform.sh")
    scenarios = read("ci/stress/scenarios.yml")

    assert "04)" in phase
    assert "test_phase4_challenge_clearance_contract.py" in phase
    assert "phase4-challenge-clearance" in phase
    assert 'phase: "04"' in manifest
    assert 'status: "in progress"' in manifest
    assert "phase4-challenge-clearance" in manifest
    assert "phase4-challenge-clearance" in stress
    assert "phase4-challenge-clearance" in scenarios
    assert "block rules remain terminal" in scenarios
