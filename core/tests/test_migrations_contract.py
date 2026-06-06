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
        "006_domain_cache_settings.sql",
        "007_cache_purge_requests.sql",
        "008_redirects_v2.sql",
        "009_page_rules_v1.sql",
        "010_ssl_certificates_v1.sql",
        "011_ssl_manual_certificates.sql",
        "012_waf_rules_v2.sql",
        "013_rate_limit_rules.sql",
        "014_origin_shield_header.sql",
        "015_usage_rollups_cache_analytics.sql",
        "016_admin_users.sql",
        "017_ssl_acme_accounts.sql",
        "018_usage_aggregates_cache_status.sql",
        "019_platform_settings.sql",
    ]


def test_stage5_index_and_audit_sql_present():
    repo_root = Path(__file__).resolve().parents[2]
    m1 = (repo_root / "core" / "database" / "migrations" / "001_api_auth_and_audit.sql").read_text()
    m2 = (repo_root / "core" / "database" / "migrations" / "002_rule_indexes.sql").read_text()
    m18 = (repo_root / "core" / "database" / "migrations" / "018_usage_aggregates_cache_status.sql").read_text()

    assert "CREATE TABLE IF NOT EXISTS schema_migrations" in m1
    assert "CREATE TABLE IF NOT EXISTS audit_log" in m1
    assert "CREATE INDEX IF NOT EXISTS idx_domains_domain ON domains(domain);" in m2
    assert "CREATE INDEX IF NOT EXISTS idx_usage_aggregates_lookup ON usage_aggregates(domain_id, bucket, bucket_ts);" in m2
    assert "IF NOT EXISTS (" in m18
    assert "conname = 'usage_aggregates_bucket_ts_domain_id_edge_node_id_status_cache_status_key'" in m18


def test_migrate_command_uses_database_pdo_entrypoint():
    repo_root = Path(__file__).resolve().parents[2]
    command = (repo_root / "core" / "app" / "Console" / "Commands" / "CdnMigrateCommand.php").read_text()

    assert "Database::pdo()" in command
    assert "Database::connection()" not in command
    assert "preg_replace('/^\\s*--.*$/m'" in command
    assert "trim((string) $executableSql) !== ''" in command
