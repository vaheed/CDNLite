CREATE INDEX IF NOT EXISTS idx_audit_log_actor_created
  ON audit_log(actor_id, created_at DESC)
  WHERE actor_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_log_resource_created
  ON audit_log(resource_type, created_at DESC);
