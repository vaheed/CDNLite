from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def test_traffic_rules_controller_validation_contract():
    assert not (REPO_ROOT / "core/app/Modules/Proxy/Services/TrafficRulesService.php").exists()
    assert not (REPO_ROOT / "core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php").exists()

    routes = (REPO_ROOT / "core/routes/api.php").read_text()
    laravel_controller = (REPO_ROOT / "core/app/Http/Controllers/Api/TrafficRulesController.php").read_text()
    laravel_service = (REPO_ROOT / "core/app/Services/ControlPlane/TrafficRulesService.php").read_text()
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
        assert field in laravel_service

    assert "use App\\Http\\Controllers\\Api\\TrafficRulesController;" in routes
    assert "use App\\Services\\ControlPlane\\TrafficRulesService;" in routes
    assert "App\\Modules\\Proxy\\Http\\Controllers\\TrafficRulesController;" not in routes
    assert "App\\Modules\\Proxy\\Services\\TrafficRulesService;" not in routes
    for command_path in (REPO_ROOT / "core/app/Console/Commands").glob("Cdn*.php"):
        command = command_path.read_text()
        if "TrafficRulesService" in command:
            assert "use App\\Services\\ControlPlane\\TrafficRulesService;" in command
            assert "App\\Modules\\Proxy\\Services\\TrafficRulesService" not in command
    for runtime_path in (
        "core/app/Modules/Proxy/Services/ConfigService.php",
        "core/app/Modules/Proxy/Services/CertRenewalService.php",
        "core/app/Modules/Proxy/Services/AcmeIssuerService.php",
        "core/app/Modules/Recommendations/Services/RecommendationService.php",
    ):
        runtime_source = (REPO_ROOT / runtime_path).read_text()
        assert "use App\\Services\\ControlPlane\\TrafficRulesService;" in runtime_source
        assert "App\\Modules\\Proxy\\Services\\TrafficRulesService" not in runtime_source
    onboarding_source = (REPO_ROOT / "core/app/Services/ControlPlane/OnboardingService.php").read_text()
    assert "namespace App\\Services\\ControlPlane;" in onboarding_source
    assert "new TrafficRulesService()" in onboarding_source
    assert "App\\Modules\\Proxy\\Services\\TrafficRulesService" not in onboarding_source
    assert "namespace App\\Http\\Controllers\\Api;" in laravel_controller
    assert "namespace App\\Services\\ControlPlane;" in laravel_service
    assert "extends \\App\\Modules\\Proxy" not in laravel_controller
    assert "extends \\App\\Modules\\Proxy" not in laravel_service
    assert "App\\Modules\\Proxy\\Services\\TrafficRulesService" not in laravel_controller
    assert "App\\Modules\\Proxy\\Services\\TrafficRulesService" not in laravel_service

    assert "invalid_field" in laravel_controller
    assert "CDNLITE_SSL_SECRET_KEY" in laravel_controller
    assert "Secrets::encrypt($privateKeyPem)" in ssl
