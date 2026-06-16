import json
import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def build_record(mode: str, ips: list[str]) -> str:
    php = """
require 'core/app/Modules/Dns/Services/EdgeHealthRecordBuilder.php';
$builder = new App\\Modules\\Dns\\Services\\EdgeHealthRecordBuilder();
echo $builder->luaRecord('A', json_decode($argv[1], true));
"""
    env = os.environ.copy()
    env["CDNLITE_EDGE_HEALTH_MODE"] = mode
    result = subprocess.run(
        ["php", "-r", php, json.dumps(ips)],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        check=True,
    )
    return result.stdout


def test_single_edge_omits_selectors():
    assert build_record("ifportup", ["185.142.95.17"]) == (
        'A "ifportup(80, {\'185.142.95.17\'}, '
        '{timeout=1, interval=10, minimumFailures=2})"'
    )


def test_multiple_edges_keep_primary_and_backup_selectors():
    record = build_record("ifportup", ["185.142.95.18", "185.142.95.17"])

    assert "{'185.142.95.17','185.142.95.18'}" in record
    assert "selector='pickclosest'" in record
    assert "failOnIncompleteCheck=false" in record


def test_single_edge_url_check_omits_selectors():
    record = build_record("ifurlup", ["185.142.95.17"])

    assert "{timeout=1, interval=10, minimumFailures=2}" in record
    assert "selector=" not in record
