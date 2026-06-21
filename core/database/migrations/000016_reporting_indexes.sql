CREATE INDEX IF NOT EXISTS idx_usage_rollups_ts
  ON usage_rollups(ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_edge_ts
  ON usage_rollups(edge_node_id, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_cache_ts
  ON usage_rollups(cache_status, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_status_ts
  ON usage_rollups(status, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_country_ts
  ON usage_rollups(client_country, ts DESC)
  WHERE client_country IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_log_created
  ON audit_log(created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_domain_created
  ON audit_log(domain_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_event_created
  ON audit_log(event, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_status_created
  ON ssl_jobs(status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_cache_purge_requests_domain_created
  ON cache_purge_requests(domain_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ssl_certificates_domain_not_after
  ON ssl_certificates(domain_id, not_after);
