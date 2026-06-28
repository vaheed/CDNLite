-- Recent request timelines page by domain and newest request first.
CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_ts_id
  ON usage_rollups(domain_id, ts DESC, id DESC);

-- Request-id lookup is domain-scoped in the activity/detail API.
CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_request_id
  ON usage_rollups(domain_id, request_id)
  WHERE request_id IS NOT NULL;

-- Status and cache filters are common dashboard activity drilldowns.
CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_status_ts
  ON usage_rollups(domain_id, status, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_cache_status_ts
  ON usage_rollups(domain_id, cache_status, ts DESC);

-- Domain audit timelines merge with request timelines by newest event first.
CREATE INDEX IF NOT EXISTS idx_audit_log_domain_created_id
  ON audit_log(domain_id, created_at DESC, id DESC);

-- Expired replay nonces are pruned by expires_at in retention jobs.
CREATE INDEX IF NOT EXISTS idx_edge_request_nonces_expires_at
  ON edge_request_nonces(expires_at);

-- Idempotency-key retention prunes old collector batches by age.
CREATE INDEX IF NOT EXISTS idx_usage_ingest_keys_created_at
  ON usage_ingest_keys(created_at);
