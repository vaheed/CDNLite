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


def test_all_durable_dns_triggers_use_the_reconciler():
    sources = [
        read("core/app/Modules/Dns/Services/DnsService.php"),
        read("core/app/Modules/Domains/Services/DomainService.php"),
        read("core/app/Modules/Edge/Http/Controllers/EdgeController.php"),
        read("core/app/Modules/Dns/Http/Controllers/EdgeNetworkController.php"),
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
