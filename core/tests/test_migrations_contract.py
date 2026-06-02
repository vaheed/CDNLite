from pathlib import Path


def test_stage5_migration_files_exist_in_order():
    repo_root = Path(__file__).resolve().parents[2]
    migration_dir = repo_root / "core" / "database" / "migrations"
    files = sorted(path.name for path in migration_dir.glob("*.sql"))

    assert files == [
        "001_api_auth_and_audit.sql",
        "002_rule_indexes.sql",
        "003_ssl_metadata.sql",
        "004_page_rules.sql",
        "005_cache_purge.sql",
        "006_site_cache_settings.sql",
        "007_cache_purge_requests.sql",
        "008_redirects_v2.sql",
        "009_page_rules_v1.sql",
        "010_ssl_certificates_v1.sql",
        "011_ssl_manual_certificates.sql",
        "012_waf_rules_v2.sql",
        "013_rate_limit_rules_v2.sql",
            "014_origin_shield_header.sql",
            "015_usage_rollups_cache_analytics.sql",
            "016_admin_users.sql",
        ]


def test_stage5_index_and_audit_sql_present():
    repo_root = Path(__file__).resolve().parents[2]
    m1 = (repo_root / "core" / "database" / "migrations" / "001_api_auth_and_audit.sql").read_text()
    m2 = (repo_root / "core" / "database" / "migrations" / "002_rule_indexes.sql").read_text()

    assert "CREATE TABLE IF NOT EXISTS schema_migrations" in m1
    assert "CREATE TABLE IF NOT EXISTS audit_log" in m1
    assert "CREATE INDEX IF NOT EXISTS idx_sites_domain ON sites(domain);" in m2
    assert "CREATE INDEX IF NOT EXISTS idx_usage_aggregates_lookup ON usage_aggregates(site_id, bucket, bucket_ts);" in m2
