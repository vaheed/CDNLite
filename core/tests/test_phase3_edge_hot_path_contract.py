from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_worker_config_cache_replaces_per_request_config_parse():
    loader = read("edge/openresty/lua/config_loader.lua")
    nginx = read("edge/openresty/nginx.conf")

    assert "local active_config = nil" in loader
    assert "function M.reload()" in loader
    assert "function M.load()" in loader
    assert "active_mtime == mtime" in loader
    assert "config_too_large" in loader
    assert "reload_successes" in loader
    assert "reload_failures" in loader
    assert "last_reload_error" in loader
    assert "current_config_checksum" in loader
    assert "config_reload_rejected" in loader
    assert "require('config_loader').reload()" in nginx
    assert "location = /__cdnlite_reload_config" in nginx
    assert "allow 127.0.0.1;" in nginx


def test_telemetry_uses_bounded_shared_queue_instead_of_per_request_file_open():
    queue = read("edge/openresty/lua/telemetry_queue.lua")
    metrics = read("edge/openresty/lua/metrics.lua")
    router = read("edge/openresty/lua/router.lua")
    ip_rules = read("edge/openresty/lua/ip_rules.lua")
    nginx = read("edge/openresty/nginx.conf")

    assert "lua_shared_dict cdnlite_metric_queue" in nginx
    assert "lua_shared_dict cdnlite_security_event_queue" in nginx
    assert "require('telemetry_queue').start()" in nginx
    assert "telemetry_queue.status()" in nginx
    assert "CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS" in nginx
    assert "CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES" in nginx

    assert "function M.enqueue(queue_name, row)" in queue
    assert "function M.flush(queue_name)" in queue
    assert "function M.status()" in queue
    assert "queue_limit()" in queue
    assert "byte_limit()" in queue
    assert "flush_successes" in queue
    assert "flush_failures" in queue
    assert "corruptions" in queue
    assert "flush_lock" in queue
    assert "dict:add(lock_key" in queue
    assert "head > tail" in queue
    assert "dict:set('tail', head)" in queue
    assert "aggregate_key(row)" in queue

    assert "telemetry_queue.enqueue('metrics', row)" in metrics
    assert "io.open('/var/lib/cdnlite/metrics.ndjson', 'a')" not in metrics
    assert "telemetry_queue.enqueue('security_events'" in router
    assert "telemetry_queue.flush('security_events')" in router
    assert "io.open(SECURITY_EVENT_PATH, 'a')" not in router
    assert "telemetry_queue.enqueue('security_events'" in ip_rules
    assert "telemetry_queue.flush('security_events')" in ip_rules
    assert "io.open(SECURITY_EVENT_PATH, 'a')" not in ip_rules


def test_capacity_defaults_and_phase_runner_are_registered():
    entrypoint = read("edge/docker-entrypoint.sh")
    compose = read("docker-compose.yml")
    phase = read("ci/phase.sh")
    manifest = read("ci/phases/phase-03.yml")
    stress = read("ci/stress-platform.sh")
    scenarios = read("ci/stress/scenarios.yml")

    for key in (
        "CDNLITE_EDGE_WORKER_PROCESSES",
        "CDNLITE_EDGE_WORKER_CONNECTIONS",
        "CDNLITE_EDGE_LIMITS_DICT_SIZE",
        "CDNLITE_EDGE_PROXY_CONNECT_TIMEOUT",
        "CDNLITE_EDGE_CLIENT_MAX_BODY_SIZE",
        "CDNLITE_EDGE_TELEMETRY_BATCH_SIZE",
    ):
        assert key in entrypoint
        assert key in compose

    assert "03)" in phase
    assert "test_phase3_edge_hot_path_contract.py" in phase
    assert "phase3-edge-hot-path" in phase
    assert "status: \"complete\"" in manifest
    assert "phase3-edge-hot-path" in manifest
    assert "phase3-edge-hot-path" in stress
    assert "manual local config reload" in scenarios
