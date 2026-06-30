from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def test_validator_domain_and_int_range_contract():
    domain_request = (REPO_ROOT / "core/app/Http/Requests/StoreDomainRequest.php").read_text()
    origin_request = (REPO_ROOT / "core/app/Http/Requests/StoreOriginRequest.php").read_text()

    assert "'domain' => ['required', 'string', 'max:253', 'regex:" in domain_request
    assert "(?!-)(?:[a-z0-9-]{1,63}\\.)+[a-z]{2,63}" in domain_request
    assert "'port' => ['nullable', 'integer', Rule::in([80, 443])]" in origin_request
    assert "'health_check_interval_seconds' => ['nullable', 'integer', 'between:5,3600']" in origin_request


def test_dns_type_specific_content_validation_contract():
    request = (REPO_ROOT / "core/app/Http/Requests/StoreDnsRecordRequest.php").read_text()
    service = (REPO_ROOT / "core/app/Services/ControlPlane/DnsRecordService.php").read_text()

    assert "Rule::in(['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'])" in request
    assert "FILTER_VALIDATE_IP, FILTER_FLAG_IPV4" in service
    assert "FILTER_VALIDATE_IP, FILTER_FLAG_IPV6" in service
    assert "invalid_dns_record_content" in service
    assert "invalid_geo_route_answer" in service
