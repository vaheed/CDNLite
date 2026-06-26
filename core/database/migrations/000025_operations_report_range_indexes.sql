CREATE INDEX IF NOT EXISTS idx_audit_log_created_actor
  ON audit_log(created_at DESC, actor_id);

CREATE INDEX IF NOT EXISTS idx_audit_log_created_resource
  ON audit_log(created_at DESC, resource_type);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_created_status
  ON ssl_jobs(created_at DESC, status);

CREATE INDEX IF NOT EXISTS idx_dns_sync_events_created_status
  ON dns_sync_events(created_at DESC, status);
