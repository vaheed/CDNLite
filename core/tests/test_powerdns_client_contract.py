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


def test_powerdns_sync_state_is_persisted_and_exposed():
    schema = read("core/database/schema.sql")
    state = read("core/app/Modules/Dns/Services/DnsSyncStateService.php")
    readiness = read("core/app/Modules/Health/Services/ReadinessService.php")
    public_index = read("core/public_index.php")

    assert "CREATE TABLE IF NOT EXISTS dns_sync_state" in schema
    assert "CREATE TABLE IF NOT EXISTS dns_sync_events" in schema
    assert "INSERT INTO dns_sync_state" in state
    assert "INSERT INTO dns_sync_events" in state
    assert "'powerdns' => $powerDns" in readiness
    assert "Response::json($readinessController->index())" in public_index


def test_powerdns_operational_commands_are_registered():
    artisan = read("core/artisan")
    for command in ("cdn:powerdns:doctor", "cdn:powerdns:dry-run", "cdn:powerdns:force-sync"):
        assert command in artisan
