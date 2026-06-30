from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_waiting_room_schema_api_and_snapshot_contract_are_wired():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000029_waiting_room.sql")
    routes = read("core/routes/api.php")
    controller = read("core/app/Http/Controllers/Api/TrafficRulesController.php")
    service = read("core/app/Services/ControlPlane/TrafficRulesService.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "CREATE TABLE IF NOT EXISTS waiting_room_policies" in schema
    assert "CREATE TABLE IF NOT EXISTS waiting_room_policies" in migration
    for field in (
        "admission_rate_per_minute",
        "queue_limit",
        "per_client_ticket_limit",
        "ticket_ttl_seconds",
        "admission_ttl_seconds",
        "unhealthy_windows",
        "healthy_windows",
        "recovery_ramp_percent",
        "waiting_room_title",
        "waiting_room_message",
    ):
        assert field in schema
        assert field in migration
        assert field in service

    assert "/domains/{domainId}/waiting-room" in routes
    assert "waiting-room/emergency/activate" in routes
    assert "waiting-room/emergency/deactivate" in routes
    assert "getWaitingRoom" in controller
    assert "updateWaitingRoom" in controller
    assert "activateWaitingRoomEmergency" in controller
    assert "deactivateWaitingRoomEmergency" in controller
    assert "validateWaitingRoom" in controller
    assert "listWaitingRoomPoliciesForConfig" in service
    assert "$hosts[$host]['waiting_room'] = $row" in config


def test_edge_waiting_room_uses_signed_tokens_bounded_state_and_local_endpoints():
    waiting_room = read("edge/openresty/lua/waiting_room.lua")
    router = read("edge/openresty/lua/router.lua")
    nginx = read("edge/openresty/nginx.conf")
    entrypoint = read("edge/docker-entrypoint.sh")

    assert "ngx.hmac_sha1" in waiting_room
    assert "__cdnlite_queue_ticket" in waiting_room
    assert "__cdnlite_admission" in waiting_room
    assert "HttpOnly; SameSite=Lax" in waiting_room
    assert "CDNLITE_EDGE_WAITING_ROOM_SECRET" in waiting_room
    assert "queue_limit" in waiting_room
    assert "admission_rate_per_minute" in waiting_room
    assert "rps_threshold" in waiting_room
    assert "status_poll_seconds" in waiting_room
    assert "cache_candidate" in waiting_room
    assert "waiting_room_cache_candidate" in waiting_room
    assert "same_host_path" in waiting_room
    assert "function M.queue_page()" in waiting_room
    assert "function M.queue_status()" in waiting_room
    assert "function M.apply(policy, domain)" in waiting_room
    assert "function M.mark_origin(domain)" in waiting_room
    assert "function M.on_log()" in waiting_room
    assert "waiting_room.apply(domain.waiting_room, domain)" in router
    assert "waiting_room.mark_origin(domain)" in router
    assert "ngx.ctx.cache_rule, ngx.ctx.cache_rules_enabled = match_cache_rule(cfg, host)" in router
    assert "local waiting_room = require('waiting_room')" in router
    assert "lua_shared_dict cdnlite_waiting_room" in nginx
    assert "location = /.well-known/cdnlite/queue" in nginx
    assert "location = /.well-known/cdnlite/queue/status" in nginx
    assert "require('waiting_room').on_log()" in nginx
    assert "CDNLITE_EDGE_WAITING_ROOM_DICT_SIZE" in entrypoint
    assert "__CDNLITE_EDGE_WAITING_ROOM_DICT_SIZE__" in entrypoint


def test_dashboard_docs_smoke_and_phase_gate_cover_waiting_room():
    types = read("dash/src/types.ts")
    tab = read("dash/src/views/domain-tabs/DomainWaitingRoomTab.vue")
    domain_detail = read("dash/src/views/DomainDetailView.vue")
    api = read("dash/src/lib/api/waitingRoom.ts")
    smoke = read("ci/smoke.sh")
    phase = read("ci/phases/phase-05.yml")
    scenarios = read("ci/stress/scenarios.yml")
    roadmap = read("docs/ROADMAP.md")
    docs_api = read("docs/api/api.md")
    docs_security = read("docs/security.md")
    docs_setup = read("docs/setup.md")
    openapi = read("docs/public/api/openapi.yaml")
    changelog = read("CHANGELOG.md")

    assert "interface WaitingRoomPolicy" in types
    assert "Waiting room" in tab
    assert "Activate for 1 hour" in tab
    assert "waitingRoomApi" in api
    assert "waiting-room/emergency/activate" in api
    assert "DomainWaitingRoomTab" in domain_detail
    assert "waiting-room" in domain_detail
    assert "schema-waiting-room" in smoke
    assert 'phase: "05"' in phase
    assert "phase5-waiting-room" in phase
    assert "phase5-waiting-room" in scenarios
    assert "bounded local edge queue state" in roadmap
    assert "Waiting room" in docs_api
    assert "Waiting Room Admission" in docs_security
    assert "CDNLITE_EDGE_WAITING_ROOM_SECRET" in docs_setup
    assert "/domains/{domainId}/waiting-room" in openapi
    assert "Phase 5 waiting room" in changelog
