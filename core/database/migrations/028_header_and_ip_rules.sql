CREATE TABLE IF NOT EXISTS domain_header_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  operation TEXT NOT NULL,
  header_name TEXT NOT NULL,
  header_value TEXT NULL,
  path_pattern TEXT NOT NULL DEFAULT '/*',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (operation IN ('set', 'remove', 'append'))
);

CREATE INDEX IF NOT EXISTS idx_domain_header_rules_domain_priority
  ON domain_header_rules(domain_id, priority, created_at);

CREATE TABLE IF NOT EXISTS domain_ip_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  rule_type TEXT NOT NULL,
  cidr TEXT NOT NULL,
  description TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (rule_type IN ('allow', 'block'))
);

CREATE INDEX IF NOT EXISTS idx_domain_ip_rules_domain_type
  ON domain_ip_rules(domain_id, rule_type, created_at);
