from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_domain_schema_has_no_origin_port():
    schema = read("core/database/schema.sql")

    assert "origin_port INTEGER" not in schema


def test_record_level_origin_proxy_and_geo_contract():
    schema = read("core/database/schema.sql")
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "origin_host TEXT NULL" in schema
    assert "origin_tls_verify TEXT NOT NULL DEFAULT 'verify'" in schema
    assert "origin_status TEXT NOT NULL DEFAULT 'pending'" in schema
    assert "geo_origins_json TEXT NULL" in schema
    assert "'proxied' => (bool)" in service
    assert "primaryProxiedRecord" in config
    assert "buildGeoOrigins($record['geo_origins']" in config


def test_duplicate_proxy_target_becomes_backup_origin():
    dns = read("core/app/Modules/Dns/Services/DnsService.php")
    origins = read("core/app/Modules/Proxy/Services/OriginHealthService.php")

    assert "proxiedRecordAtName" in dns
    assert "addBackupFromDnsRecord" in dns
    assert "['backup_origin_added'] = true" in dns
    assert "public function addBackupFromDnsRecord" in origins


def test_https_first_fallback_and_tls_verification_modes():
    selector = read("edge/openresty/lua/origin_selector.lua")

    assert "sock:connect(origin.host, 443)" in selector
    assert "sock:sslhandshake(nil, origin.host, verify)" in selector
    assert "origin.tls_verify or 'verify'" in selector
    assert "~= 'ignore'" in selector
    assert "return 'https://' .. origin.host .. ':443'" in selector
    assert "return 'http://' .. origin.host .. ':80'" in selector


def test_fresh_install_does_not_include_origin_upgrade_sql():
    assert not (ROOT / "core/database/migrations").exists() or not list(
        (ROOT / "core/database/migrations").glob("*.sql")
    )
