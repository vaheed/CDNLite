ALTER TABLE domain_ssl_settings
  ADD COLUMN IF NOT EXISTS auto_renew BOOLEAN NOT NULL DEFAULT true;

ALTER TABLE ssl_certificates
  ADD COLUMN IF NOT EXISTS acme_status TEXT NULL;

CREATE TABLE IF NOT EXISTS ssl_renewal_history (
  id TEXT PRIMARY KEY,
  certificate_id TEXT NULL REFERENCES ssl_certificates(id) ON DELETE SET NULL,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  action TEXT NOT NULL,
  status TEXT NOT NULL,
  error TEXT NULL,
  started_at BIGINT NOT NULL,
  completed_at BIGINT NULL
);

CREATE INDEX IF NOT EXISTS idx_ssl_certificates_renewal_due
  ON ssl_certificates(renewal_due_at)
  WHERE provider = 'acme' AND status <> 'revoked';

CREATE INDEX IF NOT EXISTS idx_ssl_renewal_history_domain
  ON ssl_renewal_history(domain_id, started_at DESC);
