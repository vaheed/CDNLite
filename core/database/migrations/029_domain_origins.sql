CREATE TABLE IF NOT EXISTS domain_origins (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  scheme TEXT NOT NULL DEFAULT 'http',
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 80,
  is_primary BOOLEAN NOT NULL DEFAULT true,
  health_check_path TEXT NOT NULL DEFAULT '/',
  health_check_interval_seconds INTEGER NOT NULL DEFAULT 30,
  health_check_timeout_seconds INTEGER NOT NULL DEFAULT 5,
  health_status TEXT NOT NULL DEFAULT 'unknown',
  last_check_at BIGINT NULL,
  last_error TEXT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (scheme IN ('http', 'https')),
  CHECK (port IN (80, 443)),
  CHECK (health_status IN ('healthy', 'unhealthy', 'unknown'))
);

CREATE UNIQUE INDEX IF NOT EXISTS domain_origins_one_primary_idx
  ON domain_origins (domain_id)
  WHERE is_primary = true;

INSERT INTO domain_origins (
  id, domain_id, scheme, host, port, is_primary, health_check_path,
  health_check_interval_seconds, health_check_timeout_seconds,
  health_status, last_check_at, last_error, enabled, created_at, updated_at
)
SELECT DISTINCT ON (r.domain_id)
  'origin-' || r.domain_id || '-' || r.id,
  r.domain_id,
  COALESCE(NULLIF(r.origin_scheme, ''), 'http'),
  COALESCE(NULLIF(r.origin_host, ''), NULLIF(r.origin_content, ''), r.content),
  CASE COALESCE(NULLIF(r.origin_scheme, ''), 'http') WHEN 'https' THEN 443 ELSE 80 END,
  true,
  '/',
  30,
  5,
  'unknown',
  NULL,
  NULL,
  true,
  COALESCE(r.created_at, EXTRACT(EPOCH FROM NOW())::bigint),
  COALESCE(r.updated_at, EXTRACT(EPOCH FROM NOW())::bigint)
FROM dns_records r
WHERE r.proxied = true
  AND COALESCE(NULLIF(r.origin_host, ''), NULLIF(r.origin_content, ''), r.content) IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM domain_origins o WHERE o.domain_id = r.domain_id AND o.is_primary = true)
ORDER BY r.domain_id, (r.name='@') DESC, r.created_at ASC;
