CREATE TABLE IF NOT EXISTS rate_limit_rules_v2 (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  path_prefix TEXT NOT NULL DEFAULT '/',
  key_type TEXT NOT NULL DEFAULT 'ip',
  requests_per_minute INTEGER NOT NULL,
  action TEXT NOT NULL DEFAULT 'block',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_limit_rules_v2_site_enabled_priority
  ON rate_limit_rules_v2(site_id, enabled, priority);
