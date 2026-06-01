from pathlib import Path


def test_stage7_schema_and_request_id_contract():
    repo_root = Path(__file__).resolve().parents[2]
    config_service = (repo_root / "core" / "app" / "Modules" / "Proxy" / "Services" / "ConfigService.php").read_text()
    loader = (repo_root / "edge" / "openresty" / "lua" / "config_loader.lua").read_text()
    proxy = (repo_root / "edge" / "openresty" / "lua" / "proxy.lua").read_text()
    metrics = (repo_root / "edge" / "openresty" / "lua" / "metrics.lua").read_text()
    error_page = (repo_root / "edge" / "openresty" / "lua" / "error_page.lua").read_text()

    assert "'schema_version' => 1" in config_service
    assert "EXPECTED_SCHEMA_VERSION = 1" in loader
    assert "config_schema_unsupported" in loader
    assert "decoded.schema_version == nil" in loader
    assert "X-CDNLITE-Request-Id" in proxy
    assert "request_id" in metrics
    assert "X-CDNLITE-Request-Id" in error_page
