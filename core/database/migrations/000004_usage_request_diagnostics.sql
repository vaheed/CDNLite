-- Persist enriched edge request diagnostics for the domain Activity view.
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS host TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS method TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS path TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS query_redacted JSONB NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS client_country TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS origin_id TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS origin_host TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS upstream_status TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS upstream_response_time_ms INTEGER NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS upstream_addr TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS request_time_ms INTEGER NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS router_error TEXT NULL;
ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS security_event_type TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_ts
  ON usage_rollups(domain_id, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_request_id
  ON usage_rollups(request_id)
  WHERE request_id IS NOT NULL;
