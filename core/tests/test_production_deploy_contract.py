from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_application_deploy_bundles_pin_release_images():
    for name in ("core", "starter", "edge"):
        compose = (ROOT / "deploy" / name / "docker-compose.yml").read_text()
        env = (ROOT / "deploy" / name / ".env.example").read_text()

        assert "IMAGE_TAG=CHANGE_ME_RELEASE_TAG" in env
        assert ":latest" not in compose
        assert "${IMAGE_TAG}" in compose


def test_control_plane_envs_define_canonical_cdn_targets():
    for name in ("core", "starter"):
        env = (ROOT / "deploy" / name / ".env.example").read_text()

        assert "CDNLITE_CDN_ZONE=cdn.CHANGE_ME_DOMAIN" in env
        assert "CDNLITE_CDN_PROXY_HOST=proxy.cdn.CHANGE_ME_DOMAIN" in env


def test_deployment_generator_seeds_current_edge_dns_settings():
    generator = (ROOT / "deploy" / "generate-deployment.sh").read_text()

    assert "platform.edge_dns.cdn_zone" in generator
    assert "platform.edge_dns.proxy_host" in generator
    assert "platform.edge_dns.anycast_ipv4" in generator
    assert "platform.edge_dns.anycast_ipv6" in generator
    assert '"NATIVE"' in generator
    assert "platform.cdn.zone" not in generator
    assert "platform.cdn.proxy_host" not in generator
    assert "platform.powerdns.api_base" not in generator
    assert "platform.poweradmin.url" not in generator
    assert "platform.edge_dns.base_domain" not in generator
    assert "platform.edge_dns.zone_prefix" not in generator


def test_dashboard_uses_dashboard_image_and_healthcheck():
    for name in ("core", "starter"):
        compose = (ROOT / "deploy" / name / "docker-compose.yml").read_text()
        dashboard = compose.split("  dashboard:", 1)[1]

        assert "cdnlite-dashboard:${IMAGE_TAG}" in dashboard
        assert "cdnlite-core:" not in dashboard
        assert "/healthz" in dashboard
        assert "VITE_CDNLITE_CORE_URL:" not in dashboard


def test_dnsgeo_deploy_bundle_contains_supported_topology():
    compose = (ROOT / "deploy" / "dnsgeo" / "docker-compose.yml").read_text()
    env = (ROOT / "deploy" / "dnsgeo" / ".env.example").read_text()

    for service in (
        "pdns-postgres:",
        "pdns-db-init:",
        "pdns-mmdb-updater:",
        "pdns-recursor:",
        "pdns-auth:",
        "poweradmin:",
    ):
        assert service in compose

    assert 'PDNS_EXPAND_ALIAS: "yes"' in compose
    assert 'PDNS_ENABLE_LUA_RECORDS: "yes"' in compose
    assert 'PDNS_EDNS_SUBNET_PROCESSING: "yes"' in compose
    assert "PDNS_API_BIND_ADDRESS=127.0.0.1" in env


def test_production_env_examples_include_high_volume_retention_and_dns_replication_knobs():
    env_paths = [
        ROOT / ".env.production.example",
        ROOT / "deploy" / "starter" / ".env.example",
        ROOT / "deploy" / "core" / ".env.example",
    ]

    for path in env_paths:
        env = path.read_text()
        assert "CDNLITE_RETENTION_PRUNE_ENABLED=true" in env
        assert "CDNLITE_RETENTION_INTERVAL_SECONDS=21600" in env
        assert "CDNLITE_RETENTION_BATCH_SIZE=20000" in env
        assert "CDNLITE_ANALYTICS_RETENTION_DAYS=14" in env
        assert "CDNLITE_SECURITY_EVENT_RETENTION_DAYS=90" in env
        assert "CDNLITE_DNS_EVENT_RETENTION_DAYS=30" in env
        assert "CDNLITE_SSL_JOB_RETENTION_DAYS=180" in env
        assert "CDNLITE_INGEST_KEY_RETENTION_DAYS=7" in env
        assert "CDNLITE_SYNC_INTERVAL_SECONDS=30" in env
        assert "CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS=300" in env

    for path in (ROOT / ".env.production.example", ROOT / "deploy" / "starter" / ".env.example"):
        env = path.read_text()
        assert "PDNS_ALLOW_AXFR_IPS=" in env
        assert "PDNS_ALSO_NOTIFY=" in env
        assert "PDNS_PG_MAX_WAL_SENDERS=20" in env
        assert "PDNS_PG_MAX_REPLICATION_SLOTS=20" in env
        assert "PDNS_PG_WAL_KEEP_SIZE=4096MB" in env


def test_root_env_examples_document_current_runtime_knobs():
    for path in (ROOT / ".env.example", ROOT / ".env.dev.example", ROOT / ".env.production.example"):
        env = path.read_text()
        for key in (
            "CDNLITE_ACME_DNS_VERIFY_ATTEMPTS",
            "CDNLITE_ACME_DNS_VERIFY_INTERVAL_SECONDS",
            "CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS",
            "CDNLITE_SYNC_INTERVAL_SECONDS",
            "CDNLITE_RETENTION_PRUNE_ENABLED",
            "CDNLITE_RETENTION_BATCH_SIZE",
            "CDNLITE_ANALYTICS_RETENTION_DAYS",
            "PDNS_ALLOW_AXFR_IPS",
            "PDNS_ALSO_NOTIFY",
            "PDNS_PG_MAX_WAL_SENDERS",
            "PDNS_PG_MAX_REPLICATION_SLOTS",
            "PDNS_PG_WAL_KEEP_SIZE",
            "PDNS_PG_TLS_DAYS",
        ):
            assert f"{key}=" in env
