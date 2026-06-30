from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def test_traffic_rules_controller_validation_contract():
    routes = (REPO_ROOT / "core/routes/api.php").read_text()
    service = (REPO_ROOT / "core/app/Modules/Proxy/Services/TrafficRulesService.php").read_text()
    ssl = (REPO_ROOT / "core/app/Services/ControlPlane/SslCertificateService.php").read_text()

    for route in (
        "/redirects/test",
        "/rate-limits/dry-run",
        "/cache-rules",
        "/page-rules/test",
        "/ssl/manual-certificate",
    ):
        assert route in routes

    for field in (
        "source_path",
        "target_url",
        "requests_per_minute",
        "key_header_name",
        "path_prefix",
        "ttl_seconds",
        "match_type",
        "priority",
    ):
        assert field in service

    controller = (REPO_ROOT / "core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php").read_text()

    assert "invalid_field" in controller
    assert "CDNLITE_SSL_SECRET_KEY" in controller
    assert "Secrets::encrypt($privateKeyPem)" in ssl
