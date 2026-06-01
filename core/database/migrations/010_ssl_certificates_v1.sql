CREATE TABLE IF NOT EXISTS ssl_certificates (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  provider TEXT NOT NULL DEFAULT 'manual',
  status TEXT NOT NULL,
  issuer TEXT NULL,
  serial_number TEXT NULL,
  not_before BIGINT NULL,
  not_after BIGINT NULL,
  days_until_expiry INTEGER NULL,
  renewal_due_at BIGINT NULL,
  last_checked_at BIGINT NULL,
  last_error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(site_id, hostname)
);
