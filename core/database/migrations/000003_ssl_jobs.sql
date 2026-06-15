-- Add durable SSL job progress so certificate requests can be observed without
-- blocking the dashboard on ACME issuance.
CREATE TABLE IF NOT EXISTS ssl_jobs (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  status TEXT NOT NULL,
  progress_percent INTEGER NOT NULL DEFAULT 0,
  message TEXT NOT NULL DEFAULT '',
  error_code TEXT NULL,
  error_detail TEXT NULL,
  hostnames_json TEXT NOT NULL DEFAULT '[]',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  finished_at BIGINT NULL,
  CHECK (status IN ('queued','checking_dns','creating_order','validating_challenge','issuing','installing','issued','failed','cancelled')),
  CHECK (progress_percent >= 0 AND progress_percent <= 100)
);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_domain_created
  ON ssl_jobs(domain_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_active
  ON ssl_jobs(domain_id, status)
  WHERE status IN ('queued','checking_dns','creating_order','validating_challenge','issuing','installing');
