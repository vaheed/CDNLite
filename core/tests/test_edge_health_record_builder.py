import json
import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def build_record(targets: list[dict | str], dns_type: str = "A") -> str:
    php = """
require 'core/app/Modules/Dns/Services/EdgeHealthRecordBuilder.php';

$builder = new App\\Modules\\Dns\\Services\\EdgeHealthRecordBuilder();

echo $builder->luaRecord($argv[1], json_decode($argv[2], true));
"""

    env = os.environ.copy()
    env["CDNLITE_EDGE_HEALTH_MODE"] = "geo"

    result = subprocess.run(
        ["php", "-r", php, dns_type, json.dumps(targets)],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        check=True,
    )

    return result.stdout


def test_simple_country_geo_record():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "country": "IR",
        },
        {
            "ip": "1.1.1.1",
            "country": "DE",
        },
        {
            "ip": "2.2.2.2",
            "country": "US",
        },
    ])

    assert record == (
        'A ";'
        "if country('IR') then return '185.142.97.17' "
        "elseif country('DE') then return '1.1.1.1' "
        "elseif country('US') then return '2.2.2.2' "
        "else return '185.142.97.17' end"
        '"'
    )


def test_fallback_is_always_first_edge_ip():
    record = build_record([
        {
            "ip": "3.3.3.3",
            "country": "",
        },
        {
            "ip": "185.142.97.17",
            "country": "IR",
        },
        {
            "ip": "1.1.1.1",
            "country": "DE",
        },
    ])

    assert "else return '3.3.3.3' end" in record


def test_no_port_check_and_no_selectors_are_generated():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "country": "IR",
        },
        {
            "ip": "1.1.1.1",
            "country": "DE",
        },
    ])

    assert "ifportup" not in record
    assert "ifurlup" not in record
    assert "pickclosest" not in record
    assert "selector=" not in record
    assert "backupSelector=" not in record
    assert "timeout=" not in record
    assert "interval=" not in record
    assert "minimumFailures=" not in record


def test_country_must_come_from_edge_country_field():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "",
        },
        {
            "ip": "1.1.1.1",
            "region": "fallback",
            "country": "DE",
        },
    ])

    assert "iran" not in record
    assert "country('IR')" not in record
    assert "country('DE') then return '1.1.1.1'" in record


def test_duplicate_country_returns_all_edges_for_that_country():
    record = build_record([
        {
            "ip": "1.1.1.1",
            "country": "DE",
        },
        {
            "ip": "4.4.4.4",
            "country": "DE",
        },
        {
            "ip": "5.5.5.5",
            "country": "FR",
        },
    ])

    assert "country('DE') then return {'1.1.1.1','4.4.4.4'}" in record
    assert "country('FR') then return '5.5.5.5'" in record


def test_requested_multiple_ip_country_record_shape():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "country": "IR",
        },
        {
            "ip": "2.2.2.2",
            "country": "US",
        },
        {
            "ip": "2.2.2.3",
            "country": "US",
        },
        {
            "ip": "2.2.2.4",
            "country": "US",
        },
    ])

    assert record == (
        'A ";'
        "if country('IR') then return '185.142.97.17' "
        "elseif country('US') then return {'2.2.2.2','2.2.2.3','2.2.2.4'} "
        "else return '185.142.97.17' end"
        '"'
    )


def test_invalid_ips_are_ignored():
    record = build_record([
        {
            "ip": "bad-ip",
            "country": "IR",
        },
        {
            "ip": "185.142.97.17",
            "country": "IR",
        },
    ])

    assert "bad-ip" not in record
    assert "185.142.97.17" in record


def test_aaaa_uses_ipv6_only():
    record = build_record(
        [
            {
                "ip": "185.142.97.17",
                "country": "IR",
            },
            {
                "ip": "2001:4860:4860::8888",
                "country": "US",
            },
        ],
        dns_type="AAAA",
    )

    assert record == (
        'AAAA ";'
        "if country('US') then return '2001:4860:4860::8888' "
        "else return '2001:4860:4860::8888' end"
        '"'
    )


def test_old_plain_ip_input_still_works_as_static_fallback():
    record = build_record([
        "185.142.97.17",
        "1.1.1.1",
    ])

    assert record == 'A "\'185.142.97.17\'"'
