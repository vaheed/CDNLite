from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
DNSGEO = ROOT / "infra" / "dnsgeo"


def test_compose_keeps_geoip_and_replication_without_seed_zones():
    compose = (ROOT / "docker-compose.yml").read_text()

    assert "PDNS_GEO_BOOTSTRAP_ZONE_FILE" in compose
    assert "pdns-mmdb-updater:" in compose
    assert "REPLICATION_PASSWORD" in compose
    assert "PG_MAX_REPLICATION_SLOTS" in compose

    for obsolete in (
        "SEED_BASE_ZONE",
        "SEED_LUA_EXAMPLES",
        "LUA_GEO_DEFAULT_IPV4",
        "DNS_DEFAULT_SERIAL",
        "NS1_IPV4",
    ):
        assert obsolete not in compose

    dns_reconciler = compose.split("  dns-reconciler:", 1)[1].split("\n  edge:", 1)[0]
    for setting in (
        "CDNLITE_CDN_ZONE",
        "CDNLITE_CDN_PROXY_HOST",
        "CDNLITE_EDGE_TTL",
        "CDNLITE_EDGE_HEALTH_MODE",
        "CDNLITE_EDGE_HEALTH_PORT",
        "CDNLITE_EDGE_HEALTH_URL",
        "CDNLITE_EDGE_HEALTH_TIMEOUT",
        "CDNLITE_EDGE_HEALTH_INTERVAL",
        "CDNLITE_EDGE_HEALTH_MIN_FAILURES",
        "CDNLITE_EDGE_SELECTOR",
        "CDNLITE_EDGE_BACKUP_SELECTOR",
    ):
        assert setting in dns_reconciler


def test_geoip_bootstrap_is_reserved_and_core_owned():
    bootstrap = (DNSGEO / "geo" / "lua-bootstrap.yml").read_text()

    assert "geoip-bootstrap.invalid" in bootstrap
    assert "Core owns every" in bootstrap
    assert "example.com" not in bootstrap


def test_replication_role_primary_config_and_replica_image_are_retained():
    roles = (DNSGEO / "docker" / "postgres-init" / "sql" / "10-roles-and-database.sql").read_text()
    primary = (DNSGEO / "docker" / "postgres-primary" / "configure-primary.sh").read_text()
    replica = DNSGEO / "docker" / "postgres-replica" / "Dockerfile"

    assert "CREATE ROLE replicator WITH REPLICATION LOGIN" in roles
    assert "wal_level = replica" in primary
    assert "hostssl replication" in primary
    assert replica.is_file()


def test_dnsgeo_images_are_cdnlite_specific():
    dockerfiles = sorted(DNSGEO.glob("docker/*/Dockerfile"))

    assert dockerfiles
    for dockerfile in dockerfiles:
        contents = dockerfile.read_text()
        assert 'org.opencontainers.image.title="CDNLite' in contents
        assert "org.opencontainers.image.description=" in contents


def test_github_actions_exercises_real_dns_tools_and_all_scripts():
    workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text()
    checks = (ROOT / "ci" / "powerdns_dns_checks.sh").read_text()
    e2e = (ROOT / "ci" / "e2e.sh").read_text()

    assert "dnsutils jq" in workflow
    assert "infra/dnsgeo/docker/postgres-replica/entrypoint-replica.sh" in workflow
    assert "docker compose up -d --build --wait" in workflow
    assert "docker compose --profile" not in workflow
    assert '\\"kind\\":\\"Native\\"' in checks
    assert "powerdns-rrset-write" in checks
    assert 'assert_eq "$bad_status" "401"' in checks
    assert 'assert_eq "$bad_code" "401"' in e2e
    assert "api_post_with_powerdns_retry" in e2e
    assert "'\"error\":\"powerdns_api_error\"'" in e2e
    dns_e2e = (ROOT / "ci" / "dns_e2e.sh").read_text()
    assert 'type == "A"' in dns_e2e
    assert 'type == "AAAA"' in dns_e2e
    assert 'select(.type != "SOA" and .type != "NS")' in dns_e2e
    assert "'.powerdns.api.ok'" in dns_e2e
    assert "'.powerdns.api.error'" in dns_e2e
