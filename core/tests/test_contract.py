def test_contract_schema_example():
    site = {
        "id": "11111111-1111-4111-8111-111111111111",
        "name": "demo",
        "domain": "demo.local",
        "origin_host": "core",
        "origin_port": 8080,
        "proxy_enabled": True,
    }
    assert isinstance(site["id"], str)
    assert site["proxy_enabled"] is True
