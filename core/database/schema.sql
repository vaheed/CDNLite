CREATE TABLE IF NOT EXISTS domains (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  name TEXT NOT NULL,
  domain TEXT NOT NULL UNIQUE,
  origin_shield_header_name TEXT NULL,
  origin_shield_header_value_hash TEXT NULL,
  status TEXT NOT NULL,
  nameserver_status TEXT NOT NULL DEFAULT 'unknown',
  verification_token TEXT NULL,
  last_ns_check_at BIGINT NULL,
  powerdns_zone_created BOOLEAN NOT NULL DEFAULT false,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS domain_nameservers (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  expected BOOLEAN NOT NULL DEFAULT true,
  observed BOOLEAN NOT NULL DEFAULT false,
  last_checked_at BIGINT NULL,
  UNIQUE(domain_id, hostname)
);

CREATE TABLE IF NOT EXISTS domain_routing_settings (
  domain_id TEXT PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  routing_mode TEXT NOT NULL DEFAULT 'geo',
  geo_health_port INTEGER NOT NULL DEFAULT 443,
  geo_selector TEXT NOT NULL DEFAULT 'pickclosest',
  anycast_ipv4 TEXT NULL,
  anycast_ipv6 TEXT NULL,
  anycast_cname TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (routing_mode IN ('geo', 'anycast', 'dns_only'))
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
  origin_host TEXT NULL,
  origin_tls_verify TEXT NOT NULL DEFAULT 'verify',
  origin_scheme TEXT NULL,
  origin_status TEXT NOT NULL DEFAULT 'pending',
  geo_origins_json TEXT NULL,
  routing_policy TEXT NOT NULL DEFAULT 'standard',
  canonical_edge_hostname TEXT NULL,
  status TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE,
  CHECK (origin_tls_verify IN ('verify', 'ignore')),
  CHECK (routing_policy IN ('standard', 'geo', 'anycast', 'geo_anycast'))
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
  geo_enabled BOOLEAN NOT NULL DEFAULT true,
  anycast_enabled BOOLEAN NOT NULL DEFAULT false,
  proxy_enabled BOOLEAN NOT NULL DEFAULT true,
  dns_enabled BOOLEAN NOT NULL DEFAULT true,
  cache_enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS edge_pools (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  mode TEXT NOT NULL,
  description TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (mode IN ('geo', 'anycast'))
);

CREATE TABLE IF NOT EXISTS edge_pool_members (
  id TEXT PRIMARY KEY,
  pool_id TEXT NOT NULL REFERENCES edge_pools(id) ON DELETE CASCADE,
  edge_node_id TEXT NOT NULL REFERENCES edge_nodes(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  weight INTEGER NOT NULL DEFAULT 100,
  UNIQUE(pool_id, edge_node_id)
);

CREATE TABLE IF NOT EXISTS dns_record_geo_routes (
  id TEXT PRIMARY KEY,
  dns_record_id TEXT NOT NULL REFERENCES dns_records(id) ON DELETE CASCADE,
  country_code TEXT NULL,
  edge_node_id TEXT NULL REFERENCES edge_nodes(id) ON DELETE SET NULL,
  edge_pool_id TEXT NULL REFERENCES edge_pools(id) ON DELETE SET NULL,
  answer_type TEXT NOT NULL DEFAULT 'EDGE_PROXY',
  answer_value TEXT NULL,
  priority INTEGER NOT NULL DEFAULT 0,
  weight INTEGER NOT NULL DEFAULT 100,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'),
  CHECK (answer_type IN ('A', 'AAAA', 'CNAME', 'EDGE_PROXY')),
  CHECK (
    (edge_node_id IS NOT NULL AND edge_pool_id IS NULL)
    OR (edge_node_id IS NULL AND edge_pool_id IS NOT NULL)
    OR (edge_node_id IS NULL AND edge_pool_id IS NULL AND answer_value IS NOT NULL)
  )
);

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_country_idx
  ON dns_record_geo_routes (dns_record_id, COALESCE(country_code, 'DEFAULT'));

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
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_host TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_tls_verify TEXT NOT NULL DEFAULT 'verify';
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_scheme TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS origin_status TEXT NOT NULL DEFAULT 'pending';
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS geo_origins_json TEXT NULL;
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS routing_policy TEXT NOT NULL DEFAULT 'standard';
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS canonical_edge_hostname TEXT NULL;

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
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS geo_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS anycast_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS proxy_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS dns_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS cache_enabled BOOLEAN NOT NULL DEFAULT true;

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
  managed_by TEXT NULL,
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

CREATE TABLE IF NOT EXISTS domain_ssl_settings (
  domain_id TEXT PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  force_https BOOLEAN NOT NULL DEFAULT false,
  min_tls_version TEXT NOT NULL DEFAULT '1.2',
  auto_renew BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (min_tls_version IN ('1.2', '1.3'))
);

ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS certificate_pem TEXT NULL;
ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS private_key_pem TEXT NULL;
ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS acme_status TEXT NULL;
ALTER TABLE domain_ssl_settings ADD COLUMN IF NOT EXISTS auto_renew BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE redirect_rules ADD COLUMN IF NOT EXISTS managed_by TEXT NULL;
ALTER TABLE domain_ssl_settings ALTER COLUMN force_https SET DEFAULT false;

CREATE UNIQUE INDEX IF NOT EXISTS idx_redirect_rules_force_https
  ON redirect_rules(domain_id, managed_by)
  WHERE managed_by = 'force_https';

UPDATE domain_ssl_settings s
SET force_https = false
WHERE force_https = true
  AND NOT EXISTS (
    SELECT 1 FROM redirect_rules r
    WHERE r.domain_id = s.domain_id AND r.managed_by = 'force_https'
  );

CREATE TABLE IF NOT EXISTS ssl_renewal_history (
  id TEXT PRIMARY KEY,
  certificate_id TEXT NULL REFERENCES ssl_certificates(id) ON DELETE SET NULL,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  action TEXT NOT NULL,
  status TEXT NOT NULL,
  error TEXT NULL,
  started_at BIGINT NOT NULL,
  completed_at BIGINT NULL
);

CREATE INDEX IF NOT EXISTS idx_ssl_certificates_renewal_due
  ON ssl_certificates(renewal_due_at)
  WHERE provider = 'acme' AND status <> 'revoked';
CREATE INDEX IF NOT EXISTS idx_ssl_renewal_history_domain
  ON ssl_renewal_history(domain_id, started_at DESC);

CREATE TABLE IF NOT EXISTS ssl_acme_accounts (
  id TEXT PRIMARY KEY,
  directory_url TEXT NOT NULL UNIQUE,
  kid TEXT NOT NULL,
  account_key_pem TEXT NOT NULL,
  contact_email TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
