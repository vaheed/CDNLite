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


def test_powerdns_operational_config_cannot_return_to_env():
    repository = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")
    compose = read("docker-compose.yml")
    examples = [
        read(".env.example"),
        read(".env.dev.example"),
        read(".env.production.example"),
    ]
    powerdns = read("core/app/Modules/Dns/Services/PowerDnsService.php")
    record_builder = read("core/app/Modules/Dns/Services/PowerDnsRecordBuilder.php")
    for name in [
        "POWERDNS_ENABLED",
        "POWERDNS_STRICT",
        "POWERDNS_API_URL",
        "POWERDNS_API_KEY",
        "POWERDNS_SERVER_ID",
        "POWERDNS_ZONE_KIND",
        "POWERDNS_ZONE_NAMESERVERS",
    ]:
        assert f"'{name}'" not in repository
        assert f"getenv('{name}')" not in powerdns
        assert f"getenv('{name}')" not in record_builder
        assert f"      {name}:" not in compose
        for example in examples:
            assert f"{name}=" not in example


def test_root_environment_templates_include_edge_runtime_contract():
    for filename in [".env.example", ".env.dev.example", ".env.production.example"]:
        example = read(filename)
        for variable in [
            "DEV_MODE=0",
            "EDGE_CONFIG_CACHE_PATH=",
            "EDGE_SYNC_STATUS_PATH=",
            "EDGE_CONFIG_MAX_STALE_SECONDS=",
            "SECURITY_EVENT_PATH=",
            "CDNLITE_EDGE_MMDB_FILE=",
            "CDNLITE_READINESS_SNAPSHOT_MAX_AGE_SECONDS=",
        ]:
            assert variable in example


def test_dashboard_has_all_settings_tabs_and_secret_editor():
    view = read("dash/src/views/SettingsView.vue")
    secret = read("dash/src/components/settings/SecretSettingField.vue")
    for label in ["PowerDNS", "Nameservers", "Edge DNS", "Cache Defaults", "Analytics", "Security"]:
        assert label in view
    assert "Test PowerDNS connection" in view
    assert "Static anycast bypass is active" in view
    assert "never uses Lua, country routing, or continent routing" in view
    assert "configured anycast IPs" in view
    assert "showAnycastWarning" in view
    assert "••••• (configured)" in secret
