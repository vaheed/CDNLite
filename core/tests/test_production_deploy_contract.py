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
