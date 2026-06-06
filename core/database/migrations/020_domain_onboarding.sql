ALTER TABLE domains ADD COLUMN IF NOT EXISTS nameserver_status TEXT NOT NULL DEFAULT 'unknown';
ALTER TABLE domains ADD COLUMN IF NOT EXISTS verification_token TEXT NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS last_ns_check_at BIGINT NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS powerdns_zone_created BOOLEAN NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS domain_nameservers (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  expected BOOLEAN NOT NULL DEFAULT true,
  observed BOOLEAN NOT NULL DEFAULT false,
  last_checked_at BIGINT NULL,
  UNIQUE(domain_id, hostname)
);

