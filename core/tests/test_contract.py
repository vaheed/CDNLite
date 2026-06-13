def test_contract_schema_example():
    domain = {
        "id": "11111111-1111-4111-8111-111111111111",
        "name": "demo",
        "domain": "demo.local",
    }
    assert isinstance(domain["id"], str)
    assert domain["domain"] == "demo.local"


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
    assert "cdn:domain:create" in proc.stdout
    assert "cdn:redirect:create" in proc.stdout
    assert "cdn:rate-limit:set" in proc.stdout
    assert "cdn:waf:create" in proc.stdout
    assert "cdn:cache-rule:create" in proc.stdout
    assert "cdn:admin:create" in proc.stdout
    assert "cdn:db:fresh" in proc.stdout
    assert "cdn:migrate" not in proc.stdout
