CREATE TABLE IF NOT EXISTS domains (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  name TEXT NOT NULL,
  domain TEXT NOT NULL UNIQUE,
  origin_scheme TEXT NOT NULL,
  origin_host TEXT NOT NULL,
  origin_port INTEGER NOT NULL,
  origin_shield_header_name TEXT NULL,
  origin_shield_header_value_hash TEXT NULL,
  geo_origins_json TEXT NULL,
  proxy_enabled BOOLEAN NOT NULL,
  status TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS dns_records (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL,
  type TEXT NOT NULL,
  name TEXT NOT NULL,
  content TEXT NOT NULL,
  ttl INTEGER NOT NULL,
  priority INTEGER NULL,
  proxied BOOLEAN NOT NULL,
  geo_policy_id TEXT NULL,
  edge_target TEXT NULL,
  origin_type TEXT NULL,
  origin_content TEXT NULL,
  public_type TEXT NULL,
  public_content TEXT NULL,
  status TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS edge_nodes (
  id TEXT PRIMARY KEY,
  edge_id TEXT NOT NULL UNIQUE,
  hostname TEXT NOT NULL,
  public_ip TEXT NOT NULL,
  public_ipv4 TEXT NULL,
  public_ipv6 TEXT NULL,
  region TEXT NOT NULL,
  country TEXT NULL,
  continent TEXT NULL,
  latitude DOUBLE PRECISION NULL,
  longitude DOUBLE PRECISION NULL,
  version TEXT NOT NULL,
  status TEXT NOT NULL,
  is_enabled BOOLEAN NOT NULL DEFAULT true,
  last_heartbeat BIGINT NOT NULL,
  last_heartbeat_at BIGINT NULL,
  health_status TEXT NOT NULL DEFAULT 'unknown',
  weight INTEGER NOT NULL DEFAULT 100,
  priority INTEGER NOT NULL DEFAULT 100,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS edge_dns_state (
  id SMALLINT PRIMARY KEY,
  effective_hash TEXT NOT NULL,
  synced_at BIGINT NOT NULL,
  CONSTRAINT edge_dns_state_singleton CHECK (id = 1)
);

ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS geo_policy_id TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS edge_target TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_type TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_content TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS public_type TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS public_content TEXT NULL;

ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS public_ipv4 TEXT NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS public_ipv6 TEXT NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS country TEXT NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS continent TEXT NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS latitude DOUBLE PRECISION NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS longitude DOUBLE PRECISION NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS last_heartbeat_at BIGINT NULL;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS health_status TEXT NOT NULL DEFAULT 'unknown';
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS weight INTEGER NOT NULL DEFAULT 100;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 100;

CREATE TABLE IF NOT EXISTS edge_tokens (
  edge_id TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS edge_request_nonces (
  id TEXT PRIMARY KEY,
  edge_id TEXT NOT NULL,
  nonce TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  UNIQUE(edge_id, nonce)
);

CREATE TABLE IF NOT EXISTS audit_log (
  id TEXT PRIMARY KEY,
  actor_type TEXT NOT NULL,
  actor_id TEXT NULL,
  action TEXT NOT NULL,
  resource_type TEXT NOT NULL,
  resource_id TEXT NULL,
  domain_id TEXT NULL,
  details_json TEXT NULL,
  event TEXT NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  created_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_users (
  id TEXT PRIMARY KEY,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NULL,
  status TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_sessions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  revoked_at BIGINT NULL,
  FOREIGN KEY(user_id) REFERENCES admin_users(id) ON DELETE CASCADE
);

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

CREATE TABLE IF NOT EXISTS usage_rollups (
  id TEXT PRIMARY KEY,
  ts BIGINT NOT NULL,
  domain_id TEXT NOT NULL,
  edge_node_id TEXT NOT NULL,
  requests_count BIGINT NOT NULL,
  bytes_in BIGINT NOT NULL,
  bytes_out BIGINT NOT NULL,
  status INTEGER NOT NULL,
  cache_status TEXT NOT NULL DEFAULT 'UNKNOWN',
  rule_id TEXT NULL,
  request_id TEXT NULL,
  origin_status INTEGER NULL,
  origin_time_ms INTEGER NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS usage_ingest_keys (
  idempotency_key TEXT PRIMARY KEY,
  item_count INTEGER NOT NULL,
  created_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_aggregates (
  id TEXT PRIMARY KEY,
  bucket TEXT NOT NULL,
  bucket_ts BIGINT NOT NULL,
  domain_id TEXT NOT NULL,
  edge_node_id TEXT NOT NULL,
  status INTEGER NOT NULL,
  cache_status TEXT NOT NULL DEFAULT 'UNKNOWN',
  requests_count BIGINT NOT NULL,
  bytes_in BIGINT NOT NULL,
  bytes_out BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(bucket, bucket_ts, domain_id, edge_node_id, status, cache_status),
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

ALTER TABLE usage_aggregates ADD COLUMN IF NOT EXISTS cache_status TEXT NOT NULL DEFAULT 'UNKNOWN';

DO $$
DECLARE
  constraint_name text;
BEGIN
  FOR constraint_name IN
    SELECT c.conname
    FROM pg_constraint c
    JOIN pg_class rel ON rel.oid = c.conrelid
    WHERE rel.relname = 'usage_aggregates'
      AND c.contype = 'u'
      AND (
        SELECT string_agg(att.attname, ',' ORDER BY u.ord)
        FROM unnest(c.conkey) WITH ORDINALITY AS u(attnum, ord)
        JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = u.attnum
      ) = 'bucket,bucket_ts,domain_id,edge_node_id,status'
  LOOP
    EXECUTE format('ALTER TABLE usage_aggregates DROP CONSTRAINT %I', constraint_name);
  END LOOP;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint c
    JOIN pg_class rel ON rel.oid = c.conrelid
    WHERE rel.relname = 'usage_aggregates'
      AND c.conname = 'usage_aggregates_bucket_ts_domain_id_edge_node_id_status_cache_status_key'
  ) THEN
    ALTER TABLE usage_aggregates
      ADD CONSTRAINT usage_aggregates_bucket_ts_domain_id_edge_node_id_status_cache_status_key
      UNIQUE (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status);
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS config_state (
  id SMALLINT PRIMARY KEY,
  version BIGINT NOT NULL,
  CONSTRAINT config_state_singleton CHECK (id = 1)
);

INSERT INTO config_state (id, version) VALUES (1, 0)
ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS config_snapshots (
  version BIGINT PRIMARY KEY,
  content_hash TEXT NOT NULL UNIQUE,
  payload_json TEXT NOT NULL,
  generated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS redirect_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  source_path TEXT NOT NULL,
  target_url TEXT NOT NULL,
  status_code INTEGER NOT NULL,
  priority INTEGER NOT NULL DEFAULT 100,
  match_type TEXT NOT NULL DEFAULT 'exact_path',
  preserve_query BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rate_limit_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  path_prefix TEXT NOT NULL DEFAULT '/',
  key_type TEXT NOT NULL DEFAULT 'ip',
  requests_per_minute INTEGER NOT NULL,
  action TEXT NOT NULL DEFAULT 'block',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS waf_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  name TEXT NULL,
  priority INTEGER NOT NULL DEFAULT 100,
  type TEXT NOT NULL,
  pattern TEXT NOT NULL,
  action TEXT NOT NULL DEFAULT 'block',
  description TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cache_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  path_prefix TEXT NOT NULL,
  ttl_seconds INTEGER NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS domain_cache_settings (
  domain_id TEXT PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  default_edge_ttl_seconds INTEGER NOT NULL DEFAULT 3600,
  default_browser_ttl_seconds INTEGER NULL,
  cache_query_string_mode TEXT NOT NULL DEFAULT 'include_all',
  respect_origin_cache_control BOOLEAN NOT NULL DEFAULT true,
  cache_authorized_requests BOOLEAN NOT NULL DEFAULT false,
  stale_if_error_seconds INTEGER NOT NULL DEFAULT 86400,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS cache_purge_requests (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  type TEXT NOT NULL,
  value TEXT NULL,
  status TEXT NOT NULL,
  requested_by TEXT NULL,
  edge_seen_count INTEGER NOT NULL DEFAULT 0,
  error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  completed_at BIGINT NULL
);

CREATE TABLE IF NOT EXISTS cache_purge_versions (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  scope TEXT NOT NULL,
  value TEXT NOT NULL,
  version BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(domain_id, scope, value)
);

CREATE TABLE IF NOT EXISTS page_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  pattern TEXT NOT NULL,
  actions_json TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS ssl_certificates (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  provider TEXT NOT NULL DEFAULT 'manual',
  status TEXT NOT NULL,
  issuer TEXT NULL,
  serial_number TEXT NULL,
  not_before BIGINT NULL,
  not_after BIGINT NULL,
  days_until_expiry INTEGER NULL,
  renewal_due_at BIGINT NULL,
  last_checked_at BIGINT NULL,
  last_error TEXT NULL,
  certificate_pem TEXT NULL,
  private_key_pem TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(domain_id, hostname)
);

ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS certificate_pem TEXT NULL;
ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS private_key_pem TEXT NULL;

CREATE TABLE IF NOT EXISTS ssl_acme_accounts (
  id TEXT PRIMARY KEY,
  directory_url TEXT NOT NULL UNIQUE,
  kid TEXT NOT NULL,
  account_key_pem TEXT NOT NULL,
  contact_email TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
