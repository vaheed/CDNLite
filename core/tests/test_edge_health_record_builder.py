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


def test_simple_country_and_continent_geo_record():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "IR",
            "continent": "AS",
        },
        {
            "ip": "1.1.1.1",
            "region": "eu",
            "country": "",
            "continent": "EU",
        },
        {
            "ip": "2.2.2.2",
            "region": "us",
            "country": "US",
            "continent": "NA",
        },
    ])

    assert record == (
        'A ";'
        "if country('IR') then return '185.142.97.17' "
        "elseif country('US') then return '2.2.2.2' "
        "elseif continent('EU') then return '1.1.1.1' "
        "else return '185.142.97.17' end"
        '"'
    )


def test_fallback_is_always_first_edge_ip():
    record = build_record([
        {
            "ip": "3.3.3.3",
            "region": "fallback",
            "country": "",
            "continent": "",
        },
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "IR",
            "continent": "AS",
        },
        {
            "ip": "1.1.1.1",
            "region": "eu",
            "country": "",
            "continent": "EU",
        },
    ])

    assert "else return '3.3.3.3' end" in record


def test_no_port_check_and_no_selectors_are_generated():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "IR",
            "continent": "AS",
        },
        {
            "ip": "1.1.1.1",
            "region": "eu",
            "country": "",
            "continent": "EU",
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


def test_region_aliases_work_when_country_and_continent_are_empty():
    record = build_record([
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "",
            "continent": "",
        },
        {
            "ip": "1.1.1.1",
            "region": "eu",
            "country": "",
            "continent": "",
        },
        {
            "ip": "2.2.2.2",
            "region": "us",
            "country": "",
            "continent": "",
        },
    ])

    assert "country('IR') then return '185.142.97.17'" in record
    assert "country('US') then return '2.2.2.2'" in record
    assert "continent('EU') then return '1.1.1.1'" in record


def test_country_routes_are_before_continent_routes():
    record = build_record([
        {
            "ip": "1.1.1.1",
            "region": "eu",
            "country": "",
            "continent": "EU",
        },
        {
            "ip": "4.4.4.4",
            "region": "germany",
            "country": "DE",
            "continent": "EU",
        },
        {
            "ip": "5.5.5.5",
            "region": "france",
            "country": "FR",
            "continent": "EU",
        },
    ])

    de_index = record.index("country('DE')")
    fr_index = record.index("country('FR')")
    eu_index = record.index("continent('EU')")

    assert de_index < eu_index
    assert fr_index < eu_index


def test_invalid_ips_are_ignored():
    record = build_record([
        {
            "ip": "bad-ip",
            "region": "iran",
            "country": "IR",
            "continent": "AS",
        },
        {
            "ip": "185.142.97.17",
            "region": "iran",
            "country": "IR",
            "continent": "AS",
        },
    ])

    assert "bad-ip" not in record
    assert "185.142.97.17" in record


def test_aaaa_uses_ipv6_only():
    record = build_record(
        [
            {
                "ip": "185.142.97.17",
                "region": "iran",
                "country": "IR",
                "continent": "AS",
            },
            {
                "ip": "2001:4860:4860::8888",
                "region": "us",
                "country": "US",
                "continent": "NA",
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