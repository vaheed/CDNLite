from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_powerdns_writes_are_retried_and_verified():
    service = read("core/app/Modules/Dns/Services/PowerDnsService.php")

    assert "verifyRrsets" in service
    assert "'powerdns_verify_mismatch'" in service
    assert "'powerdns_verify_delete_failed'" in service
    assert "$status === 0 || $status === 429 || $status >= 500" in service
    assert "'X-Request-ID: pdns-'" in service
    assert "2 ** $attempt" in service


def test_powerdns_hostname_content_is_normalized():
    service = read("core/app/Modules/Dns/Services/PowerDnsService.php")

    assert "['ALIAS', 'CNAME', 'MX', 'NS', 'PTR']" in service
    assert "return strtolower($value) . '.';" in service


def test_powerdns_operational_settings_are_configurable():
    settings = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")

    for name in (
        "verify_after_write",
        "retries",
        "retry_sleep_ms",
        "timeout_seconds",
    ):
        assert f"'{name}'" in settings


def test_powerdns_soa_authority_settings_are_configurable():
    settings = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")

    for name in (
        "CDNLITE_DNS_PRIMARY_NS",
        "CDNLITE_DNS_HOSTMASTER",
        "CDNLITE_DNS_SOA_REFRESH",
        "CDNLITE_DNS_SOA_RETRY",
        "CDNLITE_DNS_SOA_EXPIRE",
        "CDNLITE_DNS_SOA_MINIMUM",
        "CDNLITE_DNS_SOA_TTL",
    ):
        assert name in settings
    assert "'platform.dns_authority'" in settings


def test_powerdns_soa_serial_state_is_durable():
    schema = read("core/database/schema.sql")
    baseline = read("core/database/migrations/000001_baseline_schema.sql")
    reconciliation = read("core/database/migrations/000018_reconcile_runtime_schema.sql")

    for source in (schema, baseline, reconciliation):
        assert "CREATE TABLE IF NOT EXISTS powerdns_zone_serials" in source
        assert "zone_name TEXT PRIMARY KEY" in source
        assert "serial BIGINT NOT NULL" in source
        assert "content_hash TEXT NOT NULL" in source


def test_powerdns_soa_repair_covers_missing_duplicate_wrong_and_serial_cases():
    service = read("core/app/Modules/Dns/Services/PowerDnsSoaService.php")

    for phrase in (
        "missing SOA",
        "duplicate SOA",
        "invalid SOA content",
        "wrong primary nameserver",
        "wrong hostmaster RNAME",
        "stale or decreasing serial",
        "invalid SOA timing values",
    ):
        assert phrase in service
    assert "'type' => 'SOA'" in service
    assert "'changetype' => 'REPLACE'" in service
    assert "patchRrsets((string) $plan['zone'], [$rrset])" in service
    assert "persistSerial" in service
    assert "contentHash" in service
    assert "strtoupper((string) ($rrset['type'] ?? '')) === 'SOA'" in service
    assert "return max((int) $stored['serial'], (int) ($actualSerial ?? 0))" in service
    assert "return $floor + 1" in service
    assert "isFqdn" in service
    assert "isRname" in service


def test_powerdns_reconciler_repairs_soa_without_changing_customer_record_builder():
    reconciler = read("core/app/Modules/Dns/Services/DnsReconciler.php")
    builder = read("core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php")

    assert "PowerDnsSoaService" in reconciler
    assert "$this->soa->repair($zone, $rrsets)" in reconciler
    assert "'rrset_type' => 'SOA'" in reconciler
    assert "'soa' => $this->soa->preview($zones)" in reconciler
    assert "SOA" not in builder


def test_powerdns_doctor_and_dry_run_report_soa_state():
    doctor = read("core/app/Console/Commands/CdnPowerDnsDoctorCommand.php")
    dry_run = read("core/app/Console/Commands/CdnPowerDnsDryRunCommand.php")

    assert "'soa' => [" in doctor
    assert "'invalid_zones' => $invalidSoa" in doctor
    assert "($zone['valid'] ?? false) !== true" in doctor
    assert "(new DnsReconciler())->preview()" in dry_run


def test_powerdns_sync_state_is_persisted_and_exposed():
    schema = read("core/database/schema.sql")
    state = read("core/app/Modules/Dns/Services/DnsSyncStateService.php")
    readiness = read("core/app/Services/ControlPlane/ReadinessService.php")
    routes = read("core/routes/api.php")

    assert "CREATE TABLE IF NOT EXISTS dns_sync_state" in schema
    assert "CREATE TABLE IF NOT EXISTS dns_sync_events" in schema
    assert "INSERT INTO dns_sync_state" in state
    assert "INSERT INTO dns_sync_events" in state
    assert "'powerdns' => $powerDns" in readiness
    assert "Route::get('/v1/readiness'" in routes


def test_powerdns_operational_commands_are_registered():
    console = read("core/routes/console.php")
    for command in ("cdn:powerdns:doctor", "cdn:powerdns:dry-run", "cdn:powerdns:force-sync"):
        assert command in console
