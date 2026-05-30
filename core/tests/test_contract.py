def test_contract_schema_example():
    site = {
        "id": 1,
        "name": "demo",
        "domain": "demo.local",
        "origin_host": "core",
        "origin_port": 8080,
        "proxy_enabled": True,
    }
    assert isinstance(site["id"], int)
    assert site["proxy_enabled"] is True
