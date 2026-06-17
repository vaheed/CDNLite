-- Align origin defaults with the pool-based routing model and remove the
-- primary/backup assumptions from already-applied deployments.

ALTER TABLE domain_origins ALTER COLUMN role SET DEFAULT 'origin';
ALTER TABLE domain_origins ALTER COLUMN tls_verify SET DEFAULT 'ignore';

UPDATE domain_origins
SET
  role = COALESCE(NULLIF(role, ''), 'origin'),
  tls_verify = COALESCE(NULLIF(tls_verify, ''), 'ignore'),
  host_header = COALESCE(NULLIF(host_header, ''), host),
  sni = COALESCE(NULLIF(sni, ''), host),
  preserve_host = COALESCE(preserve_host, false),
  weight = COALESCE(weight, 1);

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_role_check'
  ) THEN
    ALTER TABLE domain_origins DROP CONSTRAINT domain_origins_role_check;
  END IF;

  ALTER TABLE domain_origins
    ADD CONSTRAINT domain_origins_role_check
    CHECK (role IN ('origin'));
END
$$;

CREATE INDEX IF NOT EXISTS domain_origins_domain_source_idx
  ON domain_origins (domain_id, source, enabled);
