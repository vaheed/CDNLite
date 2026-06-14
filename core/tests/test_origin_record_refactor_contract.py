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


def test_duplicate_proxy_target_is_stored_as_dns_record():
    dns = read("core/app/Modules/Dns/Services/DnsService.php")

    assert "proxiedRecordAtName" not in dns
    assert "addBackupFromDnsRecord($domainId, $record)" not in dns
    assert "backup_origin_added" not in dns
    assert "assertNotDuplicate" in dns


def test_https_first_fallback_and_tls_verification_modes():
    selector = read("edge/openresty/lua/origin_selector.lua")

    assert "sock:connect(origin.host, 443)" in selector
    assert "sock:sslhandshake(nil, origin.host, verify)" in selector
    assert "origin.tls_verify or 'verify'" in selector
    assert "~= 'ignore'" in selector
    assert "return 'https://' .. origin.host .. ':443'" in selector
    assert "return 'http://' .. origin.host .. ':80'" in selector


def test_fresh_install_does_not_include_origin_upgrade_sql():
    assert (ROOT / "core/database/migrations/000001_baseline_schema.sql").exists()


def test_snapshot_contains_origins_array_for_all_proxied_records():
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "'origins' => $origins" in config
    assert "private function originsFromDnsRecords" in config
    assert "'source' => 'dns_record'" in config
    assert "'dns_record_id'" in config
    assert "'primary_origin' => $primaryOrigin" in config
    assert "'backup_origin' => $backupOrigin" in config
