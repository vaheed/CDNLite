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
