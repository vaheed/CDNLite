ALTER TABLE redirect_rules
  ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_redirect_rules_force_https
  ON redirect_rules(domain_id, managed_by)
  WHERE managed_by = 'force_https';

ALTER TABLE domain_ssl_settings
  ALTER COLUMN force_https SET DEFAULT false;

UPDATE domain_ssl_settings s
SET force_https = false
WHERE force_https = true
  AND NOT EXISTS (
    SELECT 1 FROM redirect_rules r
    WHERE r.domain_id = s.domain_id AND r.managed_by = 'force_https'
  );
