from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_stress_runner_defaults_to_full_scale_and_root_compose():
    script = read("ci/stress-dns.sh")

    assert 'DOMAINS="${STRESS_DOMAINS:-10000}"' in script
    assert 'RECORDS_PER_DOMAIN="${STRESS_RECORDS_PER_DOMAIN:-1000}"' in script
    assert "TOTAL_RECORDS=$((DOMAINS * RECORDS_PER_DOMAIN))" in script
    assert "docker compose exec" in script
    assert "docker compose --profile" not in script
    assert "generate_series(1, $DOMAINS)" in script
    assert "generate_series(1, $RECORDS_PER_DOMAIN)" in script
    assert "http://127.0.0.1:8089" in script
    assert "Waiting for Core and PowerDNS readiness" in script
    assert "trap on_error ERR" in script
    assert "CORE_WRITER_SERVICES=(core)" in script
    assert 'docker compose stop "${CORE_WRITER_SERVICES[@]}"' in script
    assert "docker compose run --rm --no-deps core php artisan cdn:db:fresh --force" in script
    assert "Restoring Core writer services" in script


def test_stress_runner_proves_shared_edge_updates_and_concurrency():
    script = read("ci/stress-dns.sh")

    assert 'changed_customer_zones" == "0"' in script
    assert "STRESS_SMALL_SYNC_LIMIT_SECONDS" in script
    assert "FLAP_ITERATIONS" in script
    assert "concurrent reconciliations failed" in script
    assert "dns_sync_state WHERE status='failed' OR in_progress=true" in script
    assert "Duplicate desired rrsets detected" in script
    assert "cdn-health was unavailable during stress" in script
    assert "dns-stress-report.json" in script
    assert "required DNS scale indexes are missing" in script
    assert "pg_get_indexdef" in script
    assert "valid=true ready=true" in script
    assert "customer_zone_hashes()" in script
    assert 'select(.type != "SOA" and .type != "NS")' in script
    assert "r.change_date" not in script


def test_scale_query_indexes_exist():
    schema = read("core/database/schema.sql")

    assert "dns_records_active_domain_order_idx" in schema
    assert "dns_records_domain_status_idx" in schema
    assert "desired_dns_rrsets_owner_generation_idx" in schema
    assert "desired_dns_rrsets_zone_owner_idx" in schema


def test_fresh_install_commands_do_not_include_schema_upgrade():
    artisan = read("core/artisan")
    entrypoint = read("core/docker-entrypoint.sh")

    assert "CdnMigrateCommand" not in artisan
    assert "cdn:migrate" not in artisan
    assert "cdn:migrate" not in entrypoint
    assert not (ROOT / "core/app/Console/Commands/CdnMigrateCommand.php").exists()
