-- Make origin defaults compatible with shared hosting while preserving
-- explicit custom origin routing choices from earlier deployments.

ALTER TABLE domain_origins ADD COLUMN IF NOT EXISTS health_check_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_origins ALTER COLUMN preserve_host SET DEFAULT true;
ALTER TABLE domain_origins ALTER COLUMN tls_verify SET DEFAULT 'ignore';

UPDATE domain_origins
SET
  preserve_host = true,
  host_header = CASE
    WHEN dns_record_id IS NOT NULL
      AND (host_header IS NULL OR host_header = '' OR host_header = host)
    THEN lower(
      CASE
        WHEN dns_records.name IS NULL OR dns_records.name = '' OR dns_records.name = '@'
        THEN domains.domain
        WHEN lower(trim(trailing '.' FROM dns_records.name)) = lower(trim(trailing '.' FROM domains.domain))
          OR lower(trim(trailing '.' FROM dns_records.name)) LIKE '%.' || lower(trim(trailing '.' FROM domains.domain))
        THEN trim(trailing '.' FROM dns_records.name)
        ELSE trim(trailing '.' FROM dns_records.name) || '.' || domains.domain
      END
    )
    ELSE host_header
  END,
  sni = CASE
    WHEN dns_record_id IS NOT NULL
      AND (sni IS NULL OR sni = '' OR sni = host)
    THEN lower(
      CASE
        WHEN dns_records.name IS NULL OR dns_records.name = '' OR dns_records.name = '@'
        THEN domains.domain
        WHEN lower(trim(trailing '.' FROM dns_records.name)) = lower(trim(trailing '.' FROM domains.domain))
          OR lower(trim(trailing '.' FROM dns_records.name)) LIKE '%.' || lower(trim(trailing '.' FROM domains.domain))
        THEN trim(trailing '.' FROM dns_records.name)
        ELSE trim(trailing '.' FROM dns_records.name) || '.' || domains.domain
      END
    )
    ELSE sni
  END,
  tls_verify = COALESCE(NULLIF(tls_verify, ''), 'ignore')
FROM dns_records, domains
WHERE domain_origins.dns_record_id = dns_records.id
  AND domain_origins.domain_id = domains.id
  AND domain_origins.source = 'dns_record'
  AND domain_origins.preserve_host = false
  AND (
    domain_origins.host_header IS NULL OR domain_origins.host_header = '' OR domain_origins.host_header = domain_origins.host
    OR domain_origins.sni IS NULL OR domain_origins.sni = '' OR domain_origins.sni = domain_origins.host
  );
