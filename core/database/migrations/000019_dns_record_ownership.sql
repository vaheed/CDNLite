ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS dns_records_ssl_bootstrap_idx
  ON dns_records(domain_id, managed_by)
  WHERE managed_by = 'ssl_bootstrap';
