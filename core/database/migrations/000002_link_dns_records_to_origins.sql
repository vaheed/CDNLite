-- Link proxied DNS records to visible origin rows and add explicit edge routing metadata.
-- This is additive only: existing DNS records and manual origins are preserved in place.

ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS dns_record_id TEXT NULL;
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS source TEXT NOT NULL DEFAULT 'manual';
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'backup';
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS weight INTEGER NOT NULL DEFAULT 1;
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS host_header TEXT NULL;
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS sni TEXT NULL;
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS tls_verify TEXT NOT NULL DEFAULT 'verify';
ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS preserve_host BOOLEAN NOT NULL DEFAULT false;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_dns_record_id_fkey'
  ) THEN
    ALTER TABLE domain_origins
      ADD CONSTRAINT domain_origins_dns_record_id_fkey
      FOREIGN KEY (dns_record_id) REFERENCES dns_records(id) ON DELETE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_source_check'
  ) THEN
    ALTER TABLE domain_origins
      ADD CONSTRAINT domain_origins_source_check
      CHECK (source IN ('manual', 'dns_record', 'imported'));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_role_check'
  ) THEN
    ALTER TABLE domain_origins
      ADD CONSTRAINT domain_origins_role_check
      CHECK (role IN ('primary', 'backup'));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_tls_verify_check'
  ) THEN
    ALTER TABLE domain_origins
      ADD CONSTRAINT domain_origins_tls_verify_check
      CHECK (tls_verify IN ('verify', 'ignore'));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_weight_check'
  ) THEN
    ALTER TABLE domain_origins
      ADD CONSTRAINT domain_origins_weight_check
      CHECK (weight BETWEEN 1 AND 10000);
  END IF;
END
$$;

UPDATE domain_origins
SET
  source = COALESCE(NULLIF(source, ''), 'manual'),
  role = CASE WHEN is_primary THEN 'primary' ELSE COALESCE(NULLIF(role, ''), 'backup') END,
  host_header = COALESCE(NULLIF(host_header, ''), host),
  sni = COALESCE(NULLIF(sni, ''), host),
  tls_verify = COALESCE(NULLIF(tls_verify, ''), 'verify'),
  preserve_host = COALESCE(preserve_host, false),
  weight = COALESCE(weight, 1);

CREATE INDEX IF NOT EXISTS domain_origins_dns_record_idx
  ON domain_origins (dns_record_id)
  WHERE dns_record_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS domain_origins_domain_source_idx
  ON domain_origins (domain_id, source, enabled);
