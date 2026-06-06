CREATE TABLE IF NOT EXISTS platform_settings (
  key TEXT PRIMARY KEY,
  group_name TEXT NOT NULL,
  value_json JSONB NOT NULL,
  is_secret BOOLEAN NOT NULL DEFAULT false,
  description TEXT NULL,
  updated_by TEXT NULL,
  updated_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS platform_settings_group_name_idx
  ON platform_settings(group_name);

CREATE TABLE IF NOT EXISTS platform_settings_audit (
  id TEXT PRIMARY KEY,
  key TEXT NOT NULL,
  actor TEXT NULL,
  old_redacted JSONB NULL,
  new_redacted JSONB NULL,
  created_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS platform_settings_audit_key_created_idx
  ON platform_settings_audit(key, created_at DESC);
