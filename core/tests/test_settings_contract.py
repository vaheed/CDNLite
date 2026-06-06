from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_settings_schema_and_routes_are_present():
    schema = read("core/database/schema.sql")
    public = read("core/public_index.php")
    assert "CREATE TABLE IF NOT EXISTS platform_settings" in schema
    assert "CREATE TABLE IF NOT EXISTS platform_settings_audit" in schema
    for route in [
        "/api/v1/settings",
        "/api/v1/settings/{group}",
        "/api/v1/settings/validate",
        "/api/v1/settings/test/powerdns",
    ]:
        assert route in public


def test_secrets_are_masked_and_powerdns_uses_repository():
    repository = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")
    powerdns = read("core/app/Modules/Dns/Services/PowerDnsService.php")
    assert "'configured' =>" in repository
    assert "old_redacted" in repository
    assert "new_redacted" in repository
    assert "getenv('POWERDNS_API_KEY')" not in powerdns
    assert "platform.powerdns" in powerdns


def test_dashboard_has_all_settings_tabs_and_secret_editor():
    view = read("dash/src/views/SettingsView.vue")
    secret = read("dash/src/components/settings/SecretSettingField.vue")
    for label in ["PowerDNS", "Nameservers", "Edge DNS", "Cache Defaults", "Analytics", "Security"]:
        assert label in view
    assert "Test PowerDNS connection" in view
    assert "••••• (configured)" in secret
