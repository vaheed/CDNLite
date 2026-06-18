-- Link beginner protection choices to the advanced rules they generate.

CREATE TABLE IF NOT EXISTS protection_profiles (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  profile_key TEXT NOT NULL,
  name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  settings_json TEXT NOT NULL DEFAULT '{}',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS protection_intents (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  profile_id TEXT NULL REFERENCES protection_profiles(id) ON DELETE SET NULL,
  intent_key TEXT NOT NULL,
  name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'enabled',
  mode TEXT NOT NULL DEFAULT 'recommended',
  settings_json TEXT NOT NULL DEFAULT '{}',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS managed_rule_links (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  profile_id TEXT NULL REFERENCES protection_profiles(id) ON DELETE SET NULL,
  intent_id TEXT NULL REFERENCES protection_intents(id) ON DELETE SET NULL,
  rule_table TEXT NOT NULL,
  rule_id TEXT NOT NULL,
  template_key TEXT NOT NULL,
  managed_by TEXT NOT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  detached_at BIGINT NULL,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS profile_change_history (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  profile_id TEXT NULL REFERENCES protection_profiles(id) ON DELETE SET NULL,
  intent_id TEXT NULL REFERENCES protection_intents(id) ON DELETE SET NULL,
  action TEXT NOT NULL,
  reason TEXT NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  created_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS profile_rollback_points (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  profile_id TEXT NULL REFERENCES protection_profiles(id) ON DELETE SET NULL,
  intent_id TEXT NULL REFERENCES protection_intents(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  snapshot_json TEXT NOT NULL,
  created_at BIGINT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS protection_profiles_domain_key_idx
  ON protection_profiles(domain_id, profile_key);
CREATE INDEX IF NOT EXISTS protection_intents_domain_key_idx
  ON protection_intents(domain_id, intent_key, status);
CREATE UNIQUE INDEX IF NOT EXISTS managed_rule_links_rule_idx
  ON managed_rule_links(rule_table, rule_id)
  WHERE detached_at IS NULL;

ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS profile_id TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS intent_id TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS template_key TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS user_modified BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS last_generated_at BIGINT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS last_applied_at BIGINT NULL;

ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS profile_id TEXT NULL;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS intent_id TEXT NULL;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS template_key TEXT NULL;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS user_modified BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS last_generated_at BIGINT NULL;
ALTER TABLE rate_limit_rules ADD COLUMN IF NOT EXISTS last_applied_at BIGINT NULL;

ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS profile_id TEXT NULL;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS intent_id TEXT NULL;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS template_key TEXT NULL;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS user_modified BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS last_generated_at BIGINT NULL;
ALTER TABLE domain_ip_rules ADD COLUMN IF NOT EXISTS last_applied_at BIGINT NULL;

ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS profile_id TEXT NULL;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS intent_id TEXT NULL;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS template_key TEXT NULL;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS user_modified BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS last_generated_at BIGINT NULL;
ALTER TABLE cache_rules ADD COLUMN IF NOT EXISTS last_applied_at BIGINT NULL;

ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS profile_id TEXT NULL;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS intent_id TEXT NULL;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS template_key TEXT NULL;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS user_modified BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS last_generated_at BIGINT NULL;
ALTER TABLE domain_header_rules ADD COLUMN IF NOT EXISTS last_applied_at BIGINT NULL;
