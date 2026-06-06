ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_host TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_tls_verify TEXT NOT NULL DEFAULT 'verify';
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_scheme TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_status TEXT NOT NULL DEFAULT 'pending';
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS geo_origins_json TEXT NULL;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'domains' AND column_name = 'origin_host'
  ) THEN
    EXECUTE $copy$
      UPDATE dns_records r
      SET origin_host = d.origin_host,
          geo_origins_json = d.geo_origins_json,
          origin_scheme = CASE WHEN d.origin_scheme IN ('http', 'https') THEN d.origin_scheme ELSE NULL END,
          origin_status = CASE WHEN d.origin_scheme IN ('http', 'https') THEN 'legacy' ELSE 'pending' END
      FROM domains d
      WHERE r.domain_id = d.id
        AND r.id = (
          SELECT r2.id
          FROM dns_records r2
          WHERE r2.domain_id = d.id
          ORDER BY CASE WHEN r2.name = '@' THEN 0 ELSE 1 END, r2.created_at, r2.id
          LIMIT 1
        )
        AND COALESCE(d.origin_host, '') <> ''
    $copy$;
  END IF;
END $$;

ALTER TABLE dns_records
  DROP CONSTRAINT IF EXISTS dns_records_origin_tls_verify_check;
ALTER TABLE dns_records
  ADD CONSTRAINT dns_records_origin_tls_verify_check
  CHECK (origin_tls_verify IN ('verify', 'ignore'));

ALTER TABLE domains DROP COLUMN IF EXISTS origin_port;
ALTER TABLE domains DROP COLUMN IF EXISTS origin_scheme;
ALTER TABLE domains DROP COLUMN IF EXISTS origin_host;
ALTER TABLE domains DROP COLUMN IF EXISTS geo_origins_json;
ALTER TABLE domains DROP COLUMN IF EXISTS proxy_enabled;
