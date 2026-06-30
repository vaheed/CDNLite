from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_readiness_contract_is_structured_and_routed():
    service = (ROOT / "core/app/Modules/Health/Services/ReadinessService.php").read_text()
    routes = (ROOT / "core/routes/api.php").read_text()

    assert "/readiness" in routes
    for key in ("postgres", "powerdns_config", "powerdns_reachable", "heartbeat", "identity", "config_snapshot", "ssl_expiry"):
        assert f"'{key}'" in service
    assert "'status' => $this->groupStatus" in service
    assert "'checks' =>" in service


def test_snapshot_readiness_belongs_to_core_group_not_edge_nodes():
    service = (ROOT / "core/app/Modules/Health/Services/ReadinessService.php").read_text()
    core_checks = service.split("$coreChecks = [", 1)[1].split("];", 1)[0]
    domain_checks = service.split("$domainChecks = [", 1)[1].split("];", 1)[0]
    edge_checks = service.split("$edgeChecks = [", 1)[1].split("];", 1)[0]

    assert "$this->snapshotCheck()" in core_checks
    assert "$this->snapshotCheck()" not in edge_checks
    assert "$this->certificateExpiryCheck()" not in core_checks
    assert "$this->originHealthCheck()" not in core_checks
    assert "$this->certificateExpiryCheck()" in domain_checks
    assert "$this->originHealthCheck()" in domain_checks
    assert "$this->heartbeatCheck()" in edge_checks
    assert "$this->identityCheck()" in edge_checks


def test_snapshot_readiness_links_to_an_existing_operational_page():
    service = (ROOT / "core/app/Modules/Health/Services/ReadinessService.php").read_text()
    router = (ROOT / "dash/src/router/index.ts").read_text()
    snapshot_check = service.split("private function snapshotCheck(): array", 1)[1].split(
        "private function certificateExpiryCheck(): array", 1
    )[0]

    assert "'/config-snapshot'" not in snapshot_check
    assert snapshot_check.count("'/edge-nodes'") >= 2
    assert "{ path: '/edge-nodes'" in router


def test_powerdns_missing_configuration_is_detectable():
    powerdns = (ROOT / "core/app/Modules/Dns/Services/PowerDnsService.php").read_text()

    assert "public function isConfigured(): bool" in powerdns
    assert "public function healthCheck(): array" in powerdns
    assert "powerdns_missing_config" in powerdns
