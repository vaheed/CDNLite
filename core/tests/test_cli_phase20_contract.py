import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
ARTISAN = ROOT / "core" / "artisan"


PHASE20_COMMANDS = [
    "cdn:domain:list",
    "cdn:domain:create",
    "cdn:domain:show",
    "cdn:domain:activate",
    "cdn:domain:verify-ns",
    "cdn:domain:delete",
    "cdn:dns:list",
    "cdn:dns:create",
    "cdn:dns:delete",
    "cdn:settings:get",
    "cdn:settings:set",
    "cdn:settings:test-powerdns",
    "cdn:edge:list",
    "cdn:edge:show",
    "cdn:edge:disable",
    "cdn:edge:register-token",
    "cdn:cache:purge",
    "cdn:cache:settings",
    "cdn:ssl:list",
    "cdn:ssl:request",
    "cdn:ssl:renew-due",
    "cdn:analytics:summary",
    "cdn:origins:health-check",
    "cdn:origins:list",
    "cdn:readiness:check",
    "cdn:db:fresh",
    "cdn:bootstrap:fresh",
]


def test_phase20_cli_inventory_is_registered():
    output = subprocess.run(
        ["php", str(ARTISAN), "list"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=True,
    ).stdout

    registered = set(output.splitlines())
    missing = sorted(set(PHASE20_COMMANDS) - registered)
    assert missing == []


def test_cli_json_default_and_table_format_contract():
    command_io = (ROOT / "core/app/Support/CommandIO.php").read_text()
    artisan = ARTISAN.read_text()

    assert "json_encode($payload" in command_io
    assert "format'] ?? 'json'" in command_io
    assert "printTable" in command_io
    assert "cdn:analytics:summary" in artisan
    assert "cdn:dns:create" in artisan
    assert "cdn:dns:delete" in artisan


def test_db_fresh_requires_force():
    proc = subprocess.run(
        ["php", str(ARTISAN), "cdn:db:fresh"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
    )

    assert proc.returncode != 0
    assert "without --force" in proc.stderr


def test_bootstrap_fresh_outputs_json_without_mutation():
    proc = subprocess.run(
        ["php", str(ARTISAN), "cdn:bootstrap:fresh"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=True,
    )

    assert json.loads(proc.stdout) == {"ok": True, "seed_settings": "dev"}
