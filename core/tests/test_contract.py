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


def test_artisan_help_lists_registered_commands():
    import subprocess
    from pathlib import Path

    repo_root = Path(__file__).resolve().parents[2]
    proc = subprocess.run(
        ["php", str(repo_root / "core" / "artisan"), "help"],
        cwd=str(repo_root),
        capture_output=True,
        text=True,
        check=True,
    )

    assert "Usage: php artisan <command> [--key=value]" in proc.stdout
    assert "cdn:site:create" in proc.stdout
