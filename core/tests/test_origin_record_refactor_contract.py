from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_domain_schema_keeps_record_level_origin_fields():
    schema = read("core/database/schema.sql")

    assert "origin_port INTEGER" not in schema
    assert "dns_record_id TEXT NULL REFERENCES dns_records(id) ON DELETE CASCADE" in schema
    assert "source TEXT NOT NULL DEFAULT 'manual'" in schema
    assert "host_header TEXT NULL" in schema
    assert "sni TEXT NULL" in schema
    assert "tls_verify TEXT NOT NULL DEFAULT 'ignore'" in schema
    assert "preserve_host BOOLEAN NOT NULL DEFAULT false" in schema
    assert "domain_origins_dns_record_idx" in schema


def test_dns_origin_link_migration_is_additive():
    migration = read("core/database/migrations/000002_link_dns_records_to_origins.sql")

    assert "ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS dns_record_id" in migration
    assert "FOREIGN KEY (dns_record_id) REFERENCES dns_records(id) ON DELETE CASCADE" in migration
    assert "CREATE INDEX IF NOT EXISTS domain_origins_dns_record_idx" in migration
    assert "DROP TABLE" not in migration
    assert "TRUNCATE" not in migration


def test_record_level_origin_proxy_and_geo_contract():
    schema = read("core/database/schema.sql")
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    controller = read("core/app/Modules/Dns/Http/Controllers/DnsController.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "origin_host TEXT NULL" in schema
    assert "origin_tls_verify TEXT NOT NULL DEFAULT 'ignore'" in schema
    assert "origin_scheme TEXT NULL" in schema
    assert "origin_status TEXT NOT NULL DEFAULT 'pending'" in schema
    assert "geo_origins_json TEXT NULL" in schema
    assert "Validator::enum($input, 'origin_scheme', ['http', 'https'])" in controller
    assert "'origin_scheme' => (string) ($input['origin_scheme'] ?? 'http')" in service
    assert ":origin_scheme, :origin_status" in service
    assert "origin_scheme = :origin_scheme" in service
    assert "origin_scheme = NULL" not in service
    assert "'proxied' => (bool)" in service
    assert "selectOriginFromPool" in config
    assert "buildGeoOrigins($this->dnsRecordsGeoOrigins($records))" in config


def test_duplicate_proxy_target_creates_independent_origin():
    dns = read("core/app/Modules/Dns/Services/DnsService.php")

    assert "findCompatibleProxiedPublicRecord" not in dns
    assert "addBackupFromDnsRecord($domainId, $record)" not in dns
    assert "backup_origin_added" not in dns
    assert "assertNotDuplicate" in dns
    assert "syncFromDnsRecord($domainId, $created)" in dns
    assert "syncFromDnsRecord($domainId, $updated)" in dns
    assert "deleteForDnsRecord($domainId, $recordId)" in dns


def test_origin_service_keeps_dns_linked_and_duplicate_manual_origins_visible():
    origins = read("core/app/Modules/Proxy/Services/OriginHealthService.php")

    assert "public function syncFromDnsRecord" in origins
    assert "public function deleteForDnsRecord" in origins
    assert "source' => 'dns_record'" in origins
    assert "dns_record_id" in origins
    assert "syncDnsRecordFromLinkedOrigin" in origins
    assert "$payload['_skip_dns_record_sync'] = true" in origins
    assert "origin_scheme=:origin_scheme" in origins
    assert "$geoOrigins['DEFAULT']['host'] = $host" in origins
    assert "$geoOrigins['DEFAULT']['port'] = $scheme === 'https' ? 443 : 80" in origins
    assert "public function create" in origins


def test_edge_origin_selection_uses_explicit_scheme_except_auto():
    selector = read("edge/openresty/lua/origin_selector.lua")

    assert "candidate_origins" in selector
    assert "choose_origin" in selector
    assert "scheme == 'http' or scheme == 'https'" in selector
    assert "return scheme .. '://' .. origin.host .. ':' .. tostring(port)" in selector
    assert "scheme ~= 'auto'" in selector
    assert "invalid_origin_scheme" in selector
    assert "sock:connect(origin.host, 443)" in selector
    assert "sock:sslhandshake(nil, origin.host, verify)" in selector
    assert "origin.tls_verify or 'ignore'" in selector
    assert "~= 'ignore'" in selector
    assert "host_header = origin.host" in selector
    assert "origin.preserve_host == true" in selector


def test_fresh_install_does_not_include_origin_upgrade_sql():
    assert (ROOT / "core/database/migrations/000001_baseline_schema.sql").exists()


def test_snapshot_contains_origins_array_for_all_proxied_records():
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "'origins' => $origins" in config
    assert "private function originsForSnapshot" in config
    assert "private function originsFromDnsRecords" in config
    assert "'source' => 'dns_record'" in config
    assert "'dns_record_id'" in config
    assert "'host_header'" in config
    assert "'sni'" in config
    assert "'preserve_host'" in config
    assert "'source' => 'geo_origin'" in config
    assert "'scheme' => (string) ($origin['scheme']" in config
    assert "'origin_pool_size'" in config
