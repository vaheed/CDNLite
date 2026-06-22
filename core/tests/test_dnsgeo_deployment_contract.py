from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
DNSGEO = ROOT / "infra" / "dnsgeo"


def test_compose_keeps_geoip_and_replication_without_seed_zones():
    compose = (ROOT / "docker-compose.yml").read_text()

    assert "PDNS_GEO_BOOTSTRAP_ZONE_FILE" in compose
    assert "pdns-mmdb-updater:" in compose
    assert "REPLICATION_PASSWORD" in compose
    assert "PG_MAX_REPLICATION_SLOTS" in compose
    assert "PDNS_ALLOW_AXFR_IPS" in compose
    assert "PDNS_ALSO_NOTIFY" in compose

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

    pdns_auth = compose.split("  pdns-auth:", 1)[1].split("\n  poweradmin:", 1)[0]
    assert "restart: unless-stopped" in pdns_auth


def test_geoip_bootstrap_is_reserved_and_core_owned():
    bootstrap = (DNSGEO / "geo" / "lua-bootstrap.yml").read_text()

    assert "geoip-bootstrap.invalid" in bootstrap
    assert "Core owns every" in bootstrap
    assert "example.com" not in bootstrap


def test_replication_role_primary_config_and_replica_image_are_retained():
    roles = (DNSGEO / "docker" / "postgres-init" / "sql" / "10-roles-and-database.sql").read_text()
    primary = (DNSGEO / "docker" / "postgres-primary" / "configure-primary.sh").read_text()
    primary_tls = (DNSGEO / "docker" / "postgres-primary" / "ensure-tls.sh").read_text()
    primary_init = (DNSGEO / "docker" / "postgres-primary" / "initdb" / "10-configure-primary.sh").read_text()
    primary_entrypoint = (DNSGEO / "docker" / "postgres-primary" / "entrypoint-wrapper.sh").read_text()
    replica_entrypoint = (DNSGEO / "docker" / "postgres-replica" / "entrypoint-replica.sh").read_text()
    replica = DNSGEO / "docker" / "postgres-replica" / "Dockerfile"

    assert "CREATE ROLE replicator WITH REPLICATION LOGIN" in roles
    assert "wal_level = replica" in primary
    assert "hostssl replication" in primary
    assert "replicator/replicator.crt" in primary_tls
    assert "replicator/replicator.key" in primary_tls
    assert "cdnlite-pdns-configure-primary.sh" in primary_init
    assert "cdnlite-pdns-ensure-tls.sh" in primary_entrypoint
    assert "sslcert=${EFFECTIVE_TLS_DIR}/replicator.crt" in replica_entrypoint
    assert "sslkey=${EFFECTIVE_TLS_DIR}/replicator.key" in replica_entrypoint
    assert "pg_basebackup" in replica_entrypoint
    assert replica.is_file()


def test_powerdns_auth_renders_secondary_transfer_settings():
    entrypoint = (DNSGEO / "docker" / "pdns-auth" / "entrypoint.sh").read_text()
    dnsgeo_compose = (ROOT / "deploy" / "dnsgeo" / "docker-compose.yml").read_text()
    dnsgeo_env = (ROOT / "deploy" / "dnsgeo" / ".env.example").read_text()

    assert "PDNS_ALLOW_AXFR_IPS" in entrypoint
    assert "allow-axfr-ips=${PDNS_ALLOW_AXFR_IPS}" in entrypoint
    assert "PDNS_ALSO_NOTIFY" in entrypoint
    assert "also-notify=${PDNS_ALSO_NOTIFY}" in entrypoint
    assert "notify_config" in entrypoint
    assert "PDNS_ALLOW_AXFR_IPS" in dnsgeo_compose
    assert "PDNS_ALSO_NOTIFY" in dnsgeo_compose
    assert "CHANGE_ME_SECONDARY_IP" in dnsgeo_env


def test_upstream_generator_starts_powerdns_secondary_without_profiles():
    generator = (ROOT / "deploy" / "generate-deployment.sh").read_text()
    write_core_compose = generator.split("write_core_compose()", 1)[1].split("write_edge_compose()", 1)[0]
    write_edge_compose = generator.split("write_edge_compose()", 1)[1].split("write_platform_settings_sql()", 1)[0]

    assert 'if [ "${WITH_NPM:-yes}" = "yes" ]; then' in write_core_compose
    assert "  nginx-proxy-manager:" in write_core_compose
    assert 'profiles: ["npm"]' not in write_core_compose
    assert 'if [ "${WITH_PDNS_SECONDARY:-yes}" = "yes" ]; then' in write_edge_compose
    assert "  pdns-replica:" in write_edge_compose
    assert 'profiles: ["pdns-secondary"]' not in write_edge_compose
    assert "PowerDNS secondary mode: postgres, axfr, or none" in generator
    assert "postgres is preferred" in generator
    assert "PDNS_SECONDARY_MODE_DEFAULT" in generator
    assert "PDNS_SECONDARY_MODE=axfr" in generator
    assert "docker compose --profile pdns-secondary up" not in generator
    assert "docker compose --profile npm up" not in generator
    assert "docker compose up -d --wait" in generator
    assert "docker compose up -d --build --wait" in generator
    assert "./runtime/pdns-postgres-tls:/var/lib/postgresql/tls" in generator
    assert "./runtime/pdns-postgres-tls:/certs/postgres:ro" in generator
    assert "PDNS_ALLOW_AXFR_IPS=127.0.0.1,::1,172.31.0.0/24,${EDGE_PUBLIC_IP}" in generator
    assert "PDNS_ALSO_NOTIFY=${EDGE_PUBLIC_IP}:${EDGE_DNS_PORT}" in generator
    assert "core_dir_for_edge" in generator


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
