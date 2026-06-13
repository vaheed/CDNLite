from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_desired_state_schema_and_reconciler_lock_contract():
    schema = read("core/database/schema.sql")
    reconciler = read("core/app/Modules/Dns/Services/DnsReconciler.php")
    assert "CREATE TABLE IF NOT EXISTS dns_desired_generations" in schema
    assert "CREATE TABLE IF NOT EXISTS desired_dns_rrsets" in schema
    assert "UNIQUE(zone_name, rrset_name, rrset_type, owner)" in schema
    assert "pg_try_advisory_lock" in reconciler
    assert "pg_advisory_unlock" in reconciler
    assert "changetype' => 'DELETE'" in reconciler
    assert "patchRrsets($zone, $patch)" in reconciler
    assert "$this->builder->prune($generation)" in reconciler
    builder = read("core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php")
    assert "ON CONFLICT (zone_name, rrset_name, rrset_type, owner) DO UPDATE" in builder
    assert "generation_id <> :generation" in builder
    assert "$recordType === 'TXT'" in builder
    assert "$recordType === 'MX'" in builder
    assert "sprintf('%d %s', $priority ?? 0, $target)" in builder


def test_all_durable_dns_triggers_use_the_reconciler():
    sources = [
        read("core/app/Modules/Dns/Services/DnsService.php"),
        read("core/app/Modules/Domains/Services/DomainService.php"),
        read("core/app/Modules/Edge/Http/Controllers/EdgeController.php"),
        read("core/app/Console/Commands/CdnPowerDnsForceSyncCommand.php"),
    ]
    assert all("DnsReconciler" in source for source in sources)
    assert "syncReplace(" not in sources[0]
    assert "syncDelete(" not in sources[0]


def test_scheduled_and_operator_sync_share_the_same_command_path():
    compose = read("docker-compose.yml")
    artisan = read("core/artisan")
    assert "dns-reconciler:" in compose
    assert "php artisan cdn:dns:reconcile" in compose
    assert "CDNLITE_SYNC_INTERVAL_SECONDS" in compose
    assert "cdn:dns:reconcile" in artisan


def test_main_e2e_does_not_publish_address_rrsets_beside_apex_alias():
    e2e = read("ci/e2e.sh")

    assert 'create_dns \'{"type":"AAAA","name":"ipv6"' in e2e
    assert 'create_dns \'{"type":"AAAA","name":"@"' not in e2e


def test_main_e2e_waits_for_the_reconciled_powerdns_zone():
    e2e = read("ci/e2e.sh")

    assert "pdns_zone_is_ready()" in e2e
    assert "retry 40 1 pdns_zone_is_ready" in e2e
    assert "'.name == $zone'" in e2e
    assert 'select(.name == $name and .type == "ALIAS")' in e2e


def test_main_e2e_waits_for_a_healthy_edge_heartbeat():
    e2e = read("ci/e2e.sh")

    assert "edge_is_healthy()" in e2e
    assert "retry 20 1 edge_is_healthy" in e2e
    assert "health_status='healthy'" in e2e


def test_sync_result_binds_postgresql_boolean_explicitly():
    service = read("core/app/Modules/Dns/Services/DnsSyncStateService.php")
    assert "':ok' => $ok ? 'true' : 'false'" in service


def test_edge_state_and_shared_proxy_contract():
    schema = read("core/database/schema.sql")
    service = read("core/app/Modules/Dns/Services/EdgeDnsService.php")
    settings = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")
    assert "CREATE OR REPLACE VIEW edge_state AS" in schema
    assert "CREATE TABLE IF NOT EXISTS edge_state_generations" in schema
    assert "e.health_status = 'healthy'" in schema
    assert "e.anycast_enabled AS anycast" in schema
    assert "ORDER BY anycast DESC" in service
    assert "array_merge($pool['anycast'][$family], $pool['unicast'][$family])" in service
    assert "CDNLITE_CDN_ZONE" in settings
    assert "CDNLITE_CDN_PROXY_HOST" in settings
    assert "CDNLITE_EDGE_BASE_DOMAIN" not in settings
    assert "platform_soa" not in service


def test_agent_heartbeat_marks_a_successful_runtime_healthy():
    heartbeat = read("edge/agent/heartbeat.sh")
    assert '\\"health_status\\":\\"healthy\\"' in heartbeat
