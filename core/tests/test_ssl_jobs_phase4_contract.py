from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_ssl_jobs_migration_and_schema_are_additive():
    migration = read("core/database/migrations/000003_ssl_jobs.sql")
    schema = read("core/database/schema.sql")

    for source in (migration, schema):
        assert "CREATE TABLE IF NOT EXISTS ssl_jobs" in source
        assert "progress_percent INTEGER NOT NULL DEFAULT 0" in source
        assert "hostnames_json TEXT NOT NULL DEFAULT '[]'" in source
        assert "idx_ssl_jobs_domain_created" in source
        assert "idx_ssl_jobs_active" in source
        assert "DROP TABLE" not in source
        assert "TRUNCATE" not in source


def test_ssl_request_endpoint_returns_job_and_status_route():
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    renewals = read("core/app/Modules/Proxy/Services/CertRenewalService.php")
    public_index = read("core/public_index.php")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "public function requestSslJob" in service
    assert "INSERT INTO ssl_jobs" in service
    assert "defaultManagedSslHostnames" in service
    assert "'*.' . $domain" in service
    assert "AuditLog::write('ssl.requested'" in service
    assert "'job_id' => $job['id']" in service
    assert "public function getSslJob" in service
    assert "public function getSslJob(string $domainId, string $jobId)" in controller
    assert "/api/v1/domains/{domainId}/ssl/jobs/{jobId}" in public_index
    assert "'jobs' => $this->certificates->listSslJobs($domainId)" in renewals
    assert "/api/v1/domains/{domainId}/ssl/jobs/{jobId}" in docs
    assert "/api/v1/domains/{domainId}/ssl/jobs/{jobId}:" in openapi


def test_ssl_lifecycle_events_and_job_transitions_are_recorded():
    renewals = read("core/app/Modules/Proxy/Services/CertRenewalService.php")
    issuer = read("core/app/Modules/Proxy/Services/AcmeIssuerService.php")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    command = read("core/app/Console/Commands/CdnSslRenewDueCommand.php")
    compose = read("docker-compose.yml")

    for event in [
        "ssl.validation_pending",
        "ssl.issued",
        "ssl.failed",
    ]:
        assert event in renewals
    assert "public function processQueuedJobs" in renewals
    assert "WHERE status='queued'" in renewals
    assert "'queued_issuance'" in renewals
    assert "processQueuedJobs()" in command
    assert "renewDue()" in command
    assert "CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS:-30" in compose
    assert "ssl.dns_challenge_created" in issuer
    assert "AuditLog::write('ssl.dns_challenge_created'" in issuer
    assert "private function updateActiveJobs" in renewals
    assert "defaultManagedSslHostnames" in renewals
    assert "defaultManagedSslHostnames" in issuer
    assert "UPDATE ssl_jobs" in renewals
    assert "'checking_dns'" in renewals
    assert "'validating_challenge'" in renewals
    assert "'issued'" in renewals
    assert "'failed'" in renewals
    assert "finished_at=:finished_at" in renewals
    assert "safeErrorMessage" in renewals
    store_certificate = service.split("public function storeIssuedSslCertificate", 1)[1].split("private function domainForSsl", 1)[0]
    assert "invalidateConfigSnapshot" in store_certificate


def test_wildcard_ssl_is_automatic_after_dns_verification():
    verification = read("core/app/Modules/Domains/Services/DomainVerificationService.php")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    schema = read("core/database/schema.sql")
    edge_tls = read("edge/openresty/lua/tls_cert.lua")

    assert "queueManagedWildcardSsl($domainId)" in verification
    assert "ensureManagedWildcardSslJob" in service
    assert "hasActiveSslJob" in service
    assert "hasActiveManagedCertificate" in service
    assert "DEFAULT true" in schema
    assert "matches_hostname" in edge_tls
    assert "certificate_hostname = cert_hostname" in edge_tls


def test_dashboard_polls_ssl_job_progress():
    types = read("dash/src/types.ts")
    api = read("dash/src/lib/api/ssl.ts")
    view = read("dash/src/views/domain-tabs/DomainSslTab.vue")

    assert "export interface SslJob" in types
    assert "export interface SslJobRequest" in types
    assert "api.post<SslJobRequest>" in api
    assert "ssl/jobs/${jobId}" in api
    assert "SSL request progress" in view
    assert "activeJob" in view
    assert "progress_percent" in view
    assert "sslApi.job(props.domainId, activeJob.value.id)" in view
    assert "queued: 'Queued'" in view
    assert "SSL request queued" in view
    assert "DNS validation in progress" in view
    assert "Certificate issued" in view
    assert "SSL failed" in view
    assert "retryFailedJob" in view
    assert "{ hostnames: job.hostnames }" in view
