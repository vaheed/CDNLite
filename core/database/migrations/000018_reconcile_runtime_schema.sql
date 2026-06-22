-- Reconcile runtime objects that were added to the fresh schema after some
-- development volumes had already adopted the migration baseline.
CREATE TABLE IF NOT EXISTS powerdns_zone_serials (
  zone_name TEXT PRIMARY KEY,
  serial BIGINT NOT NULL,
  content_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS client_ip TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_usage_rollups_client_ip_ts
  ON usage_rollups(client_ip, ts DESC)
  WHERE client_ip IS NOT NULL;
