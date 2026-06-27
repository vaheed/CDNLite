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
  origin_type TEXT NULL,
  origin_content TEXT NULL,
  public_type TEXT NULL,
  public_content TEXT NULL,
  origin_host TEXT NULL,
  origin_tls_verify TEXT NOT NULL DEFAULT 'ignore',
  origin_scheme TEXT NULL,
  origin_status TEXT NOT NULL DEFAULT 'pending',
  geo_origins_json TEXT NULL,
  routing_policy TEXT NOT NULL DEFAULT 'standard',
  managed_by TEXT NULL,
  status TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE,
  CHECK (origin_tls_verify IN ('verify', 'ignore')),
  CHECK (routing_policy IN ('standard', 'geo', 'anycast', 'geo_anycast')),
  CHECK (type IN ('A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV')),
  CHECK (ttl BETWEEN 60 AND 86400),
  CHECK (priority IS NULL OR priority BETWEEN 0 AND 65535),
  CHECK (status IN ('active', 'disabled'))
);

CREATE UNIQUE INDEX IF NOT EXISTS dns_records_exact_value_idx
  ON dns_records(domain_id, UPPER(type), LOWER(name), content)
  WHERE status = 'active';

CREATE INDEX IF NOT EXISTS dns_records_active_domain_order_idx
  ON dns_records(domain_id, name, id)
  WHERE status = 'active';

CREATE INDEX IF NOT EXISTS dns_records_domain_status_idx
  ON dns_records(domain_id, status);

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
  applied_config_version INTEGER NULL,
  last_config_pull_at BIGINT NULL,
  config_apply_error TEXT NULL,
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

CREATE OR REPLACE VIEW edge_state AS
SELECT
  e.id AS edge_node_id,
  e.edge_id,
  address.ip,
  address.ip_family,
  e.region,
  e.country,
  e.continent,
  e.anycast_enabled AS anycast,
  (
    e.is_enabled = true
    AND e.dns_enabled = true
    AND e.status = 'online'
    AND e.health_status = 'healthy'
    AND COALESCE(e.last_heartbeat_at, e.last_heartbeat) > EXTRACT(EPOCH FROM NOW())::BIGINT - 90
  ) AS healthy,
  COALESCE(e.last_heartbeat_at, e.last_heartbeat) AS last_check_at,
  e.updated_at AS state_updated_at
FROM edge_nodes e
CROSS JOIN LATERAL (
  VALUES
    (NULLIF(e.public_ipv4, ''), 'A'),
    (NULLIF(e.public_ipv6, ''), 'AAAA'),
    (CASE WHEN NULLIF(e.public_ipv4, '') IS NULL AND e.public_ip NOT LIKE '%:%' THEN NULLIF(e.public_ip, '') END, 'A'),
    (CASE WHEN NULLIF(e.public_ipv6, '') IS NULL AND e.public_ip LIKE '%:%' THEN NULLIF(e.public_ip, '') END, 'AAAA')
) AS address(ip, ip_family)
WHERE address.ip IS NOT NULL;

CREATE TABLE IF NOT EXISTS edge_state_generations (
  id BIGSERIAL PRIMARY KEY,
  state_hash TEXT NOT NULL UNIQUE,
  created_at BIGINT NOT NULL
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
  route_scope TEXT NOT NULL DEFAULT 'country',
  country_code TEXT NULL,
  continent_code TEXT NULL,
  edge_node_id TEXT NULL REFERENCES edge_nodes(id) ON DELETE SET NULL,
  edge_pool_id TEXT NULL REFERENCES edge_pools(id) ON DELETE SET NULL,
  answer_type TEXT NOT NULL,
  answer_value TEXT NULL,
  priority INTEGER NOT NULL DEFAULT 0,
  weight INTEGER NOT NULL DEFAULT 100,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (route_scope IN ('default', 'country', 'continent')),
  CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'),
  CHECK (continent_code IS NULL OR continent_code IN ('AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA')),
  CHECK (answer_type IN ('A', 'AAAA')),
  CHECK (edge_node_id IS NULL AND edge_pool_id IS NULL AND answer_value IS NOT NULL),
  CHECK (
    (route_scope = 'default' AND country_code IS NULL AND continent_code IS NULL)
    OR (route_scope = 'country' AND country_code IS NOT NULL AND continent_code IS NULL)
    OR (route_scope = 'continent' AND country_code IS NULL AND continent_code IS NOT NULL)
  )
);

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_default_idx
  ON dns_record_geo_routes (dns_record_id)
  WHERE route_scope = 'default';

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_country_idx
  ON dns_record_geo_routes (dns_record_id, country_code)
  WHERE route_scope = 'country';

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_continent_idx
  ON dns_record_geo_routes (dns_record_id, continent_code)
  WHERE route_scope = 'continent';

CREATE TABLE IF NOT EXISTS domain_origins (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  dns_record_id TEXT NULL REFERENCES dns_records(id) ON DELETE CASCADE,
  source TEXT NOT NULL DEFAULT 'manual',
  role TEXT NOT NULL DEFAULT 'origin',
  weight INTEGER NOT NULL DEFAULT 1,
  scheme TEXT NOT NULL DEFAULT 'http',
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 80,
  host_header TEXT NULL,
  sni TEXT NULL,
  tls_verify TEXT NOT NULL DEFAULT 'ignore',
  preserve_host BOOLEAN NOT NULL DEFAULT true,
  is_primary BOOLEAN NOT NULL DEFAULT false,
  health_check_enabled BOOLEAN NOT NULL DEFAULT false,
  health_check_path TEXT NOT NULL DEFAULT '/',
  health_check_interval_seconds INTEGER NOT NULL DEFAULT 30,
  health_check_timeout_seconds INTEGER NOT NULL DEFAULT 5,
  health_status TEXT NOT NULL DEFAULT 'unknown',
  last_check_at BIGINT NULL,
  last_error TEXT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (scheme IN ('http', 'https')),
  CHECK (port IN (80, 443)),
  CHECK (source IN ('manual', 'dns_record', 'imported')),
  CHECK (role IN ('origin')),
  CHECK (tls_verify IN ('verify', 'ignore')),
  CHECK (weight BETWEEN 1 AND 10000),
  CHECK (health_status IN ('healthy', 'unhealthy', 'unknown'))
);

CREATE INDEX IF NOT EXISTS domain_origins_dns_record_idx
  ON domain_origins (dns_record_id)
  WHERE dns_record_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS domain_origins_domain_source_idx
  ON domain_origins (domain_id, source, enabled);

CREATE TABLE IF NOT EXISTS dns_desired_generations (
  id BIGSERIAL PRIMARY KEY,
  desired_hash TEXT NOT NULL UNIQUE,
  created_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS desired_dns_rrsets (
  id BIGSERIAL PRIMARY KEY,
  zone_name TEXT NOT NULL,
  rrset_name TEXT NOT NULL,
  rrset_type TEXT NOT NULL,
  ttl INTEGER NOT NULL,
  records_json JSONB NOT NULL,
  owner TEXT NOT NULL DEFAULT 'cdnlite',
  source TEXT NOT NULL,
  generation_id BIGINT NOT NULL REFERENCES dns_desired_generations(id),
  desired_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(zone_name, rrset_name, rrset_type, owner)
);

CREATE INDEX IF NOT EXISTS desired_dns_rrsets_owner_generation_idx
  ON desired_dns_rrsets(owner, generation_id);

CREATE INDEX IF NOT EXISTS desired_dns_rrsets_zone_owner_idx
  ON desired_dns_rrsets(zone_name, owner);

CREATE TABLE IF NOT EXISTS powerdns_zone_serials (
  zone_name TEXT PRIMARY KEY,
  serial BIGINT NOT NULL,
  content_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS dns_sync_state (
  zone_name TEXT PRIMARY KEY,
  desired_hash TEXT NULL,
  applied_hash TEXT NULL,
  generation_id BIGINT NULL REFERENCES dns_desired_generations(id),
  status TEXT NOT NULL DEFAULT 'unknown',
  last_attempt_at BIGINT NULL,
  last_success_at BIGINT NULL,
  last_error TEXT NULL,
  last_status_code INTEGER NULL,
  pending_changes INTEGER NOT NULL DEFAULT 0,
  in_progress BOOLEAN NOT NULL DEFAULT false,
  updated_at BIGINT NOT NULL,
  CHECK (status IN ('unknown', 'syncing', 'ok', 'failed'))
);

CREATE TABLE IF NOT EXISTS dns_sync_events (
  id BIGSERIAL PRIMARY KEY,
  zone_name TEXT NOT NULL,
  rrset_name TEXT NULL,
  rrset_type TEXT NULL,
  action TEXT NOT NULL,
  status TEXT NOT NULL,
  status_code INTEGER NULL,
  error TEXT NULL,
  desired_hash TEXT NULL,
  applied_hash TEXT NULL,
  generation_id BIGINT NULL REFERENCES dns_desired_generations(id),
  created_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_dns_sync_events_zone_created
  ON dns_sync_events(zone_name, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_dns_sync_events_created_status
  ON dns_sync_events(created_at DESC, status);

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

CREATE INDEX IF NOT EXISTS idx_audit_log_created
  ON audit_log(created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_domain_created
  ON audit_log(domain_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_event_created
  ON audit_log(event, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_actor_created
  ON audit_log(actor_id, created_at DESC)
  WHERE actor_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_log_resource_created
  ON audit_log(resource_type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_log_created_actor
  ON audit_log(created_at DESC, actor_id);

CREATE INDEX IF NOT EXISTS idx_audit_log_created_resource
  ON audit_log(created_at DESC, resource_type);

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
CREATE INDEX IF NOT EXISTS idx_admin_sessions_user_id ON admin_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_active_lookup ON admin_sessions(token_hash, expires_at) WHERE revoked_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_admin_sessions_expiry ON admin_sessions(expires_at, revoked_at);

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

CREATE TABLE IF NOT EXISTS database_workload_budgets (
  workload TEXT PRIMARY KEY,
  role_name TEXT NOT NULL,
  connection_pool TEXT NOT NULL,
  max_connections INTEGER NOT NULL,
  statement_timeout_ms INTEGER NOT NULL,
  lock_timeout_ms INTEGER NOT NULL,
  max_query_range_seconds INTEGER NULL,
  max_result_rows INTEGER NULL,
  notes TEXT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (workload IN ('control', 'telemetry_ingest', 'reporting', 'jobs', 'maintenance')),
  CHECK (max_connections BETWEEN 1 AND 1000),
  CHECK (statement_timeout_ms BETWEEN 100 AND 600000),
  CHECK (lock_timeout_ms BETWEEN 100 AND 60000),
  CHECK (max_query_range_seconds IS NULL OR max_query_range_seconds BETWEEN 60 AND 31622400),
  CHECK (max_result_rows IS NULL OR max_result_rows BETWEEN 1 AND 100000)
);

INSERT INTO database_workload_budgets
  (workload, role_name, connection_pool, max_connections, statement_timeout_ms, lock_timeout_ms, max_query_range_seconds, max_result_rows, notes, updated_at)
VALUES
  ('control', 'cdnlite_control', 'api-writes', 20, 5000, 1000, NULL, 500, 'Transactional API and configuration writes.', EXTRACT(EPOCH FROM NOW())::BIGINT),
  ('telemetry_ingest', 'cdnlite_telemetry', 'telemetry-ingest', 10, 10000, 1000, 86400, 1000, 'Bounded micro-batch edge metrics and security-event ingestion.', EXTRACT(EPOCH FROM NOW())::BIGINT),
  ('reporting', 'cdnlite_reporting', 'reporting-read', 8, 3000, 500, 31622400, 1000, 'Read-only bounded reports with mandatory time ranges and row limits.', EXTRACT(EPOCH FROM NOW())::BIGINT),
  ('jobs', 'cdnlite_jobs', 'background-jobs', 10, 30000, 2000, 2592000, 5000, 'Rollups, reconciliation, certificate, DNS, and maintenance jobs.', EXTRACT(EPOCH FROM NOW())::BIGINT),
  ('maintenance', 'cdnlite_maintenance', 'maintenance', 2, 120000, 5000, 31622400, 10000, 'Retention, partition maintenance, backfill, and benchmark tasks.', EXTRACT(EPOCH FROM NOW())::BIGINT)
ON CONFLICT (workload) DO UPDATE SET
  role_name = EXCLUDED.role_name,
  connection_pool = EXCLUDED.connection_pool,
  max_connections = EXCLUDED.max_connections,
  statement_timeout_ms = EXCLUDED.statement_timeout_ms,
  lock_timeout_ms = EXCLUDED.lock_timeout_ms,
  max_query_range_seconds = EXCLUDED.max_query_range_seconds,
  max_result_rows = EXCLUDED.max_result_rows,
  notes = EXCLUDED.notes,
  updated_at = EXCLUDED.updated_at;

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
  host TEXT NULL,
  method TEXT NULL,
  path TEXT NULL,
  query_redacted JSONB NULL,
  client_ip TEXT NULL,
  client_country TEXT NULL,
  origin_id TEXT NULL,
  origin_host TEXT NULL,
  upstream_status TEXT NULL,
  upstream_response_time_ms INTEGER NULL,
  upstream_addr TEXT NULL,
  request_time_ms INTEGER NULL,
  router_error TEXT NULL,
  security_event_type TEXT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_ts
  ON usage_rollups(domain_id, ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_request_id
  ON usage_rollups(request_id)
  WHERE request_id IS NOT NULL;

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

CREATE INDEX IF NOT EXISTS idx_usage_rollups_client_ip_ts
  ON usage_rollups(client_ip, ts DESC)
  WHERE client_ip IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_bucket_ts
  ON usage_rollups(domain_id, ((ts / 60) * 60) DESC, cache_status, status);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_ts_brin
  ON usage_rollups USING BRIN(ts);

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

CREATE INDEX IF NOT EXISTS idx_usage_aggregates_bucket_ts
  ON usage_aggregates(bucket, bucket_ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_aggregates_domain_bucket_ts
  ON usage_aggregates(domain_id, bucket, bucket_ts DESC);

CREATE TABLE IF NOT EXISTS telemetry_ingest_batches (
  batch_id TEXT PRIMARY KEY,
  source_edge_id TEXT NOT NULL,
  idempotency_key TEXT NOT NULL UNIQUE,
  event_count INTEGER NOT NULL,
  accepted_count INTEGER NOT NULL DEFAULT 0,
  rejected_count INTEGER NOT NULL DEFAULT 0,
  first_event_ts BIGINT NULL,
  last_event_ts BIGINT NULL,
  payload_bytes INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL,
  rejection_reason TEXT NULL,
  ingested_at BIGINT NOT NULL,
  CHECK (event_count BETWEEN 0 AND 1000),
  CHECK (accepted_count BETWEEN 0 AND 1000),
  CHECK (rejected_count BETWEEN 0 AND 1000),
  CHECK (payload_bytes BETWEEN 0 AND 1048576),
  CHECK (status IN ('accepted', 'partial', 'rejected', 'duplicate'))
);

CREATE INDEX IF NOT EXISTS telemetry_ingest_batches_edge_ingested_idx
  ON telemetry_ingest_batches(source_edge_id, ingested_at DESC);

CREATE TABLE IF NOT EXISTS telemetry_rejected_events (
  id TEXT PRIMARY KEY,
  batch_id TEXT NULL REFERENCES telemetry_ingest_batches(batch_id) ON DELETE SET NULL,
  source_edge_id TEXT NOT NULL,
  event_id TEXT NULL,
  event_ts BIGINT NULL,
  reason TEXT NOT NULL,
  payload_excerpt JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS telemetry_rejected_events_created_idx
  ON telemetry_rejected_events(created_at DESC);

CREATE TABLE IF NOT EXISTS reporting_rollup_watermarks (
  stream TEXT NOT NULL,
  bucket TEXT NOT NULL,
  domain_id TEXT NOT NULL,
  source_watermark_ts BIGINT NOT NULL,
  last_success_at BIGINT NOT NULL,
  last_error TEXT NULL,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (stream, bucket, domain_id),
  CHECK (bucket IN ('minute', 'hour', 'day'))
);

CREATE TABLE IF NOT EXISTS reporting_reconciliation_results (
  id TEXT PRIMARY KEY,
  stream TEXT NOT NULL,
  domain_id TEXT NULL,
  bucket TEXT NOT NULL,
  range_start BIGINT NOT NULL,
  range_end BIGINT NOT NULL,
  raw_total BIGINT NOT NULL,
  aggregate_total BIGINT NOT NULL,
  duplicate_count BIGINT NOT NULL DEFAULT 0,
  missing_count BIGINT NOT NULL DEFAULT 0,
  status TEXT NOT NULL,
  details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at BIGINT NOT NULL,
  CHECK (range_start < range_end),
  CHECK (bucket IN ('minute', 'hour', 'day')),
  CHECK (status IN ('ok', 'mismatch', 'missing', 'duplicate'))
);

CREATE INDEX IF NOT EXISTS reporting_reconciliation_domain_created_idx
  ON reporting_reconciliation_results(domain_id, created_at DESC);

CREATE TABLE IF NOT EXISTS analytics_rollup_jobs (
  id TEXT PRIMARY KEY,
  domain_id TEXT NULL REFERENCES domains(id) ON DELETE CASCADE,
  bucket TEXT NULL,
  range_start BIGINT NULL,
  range_end BIGINT NULL,
  status TEXT NOT NULL,
  requested_by TEXT NOT NULL DEFAULT 'api',
  progress_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  error TEXT NULL,
  cancel_requested BOOLEAN NOT NULL DEFAULT false,
  locked_by TEXT NULL,
  locked_at BIGINT NULL,
  started_at BIGINT NULL,
  finished_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (bucket IS NULL OR bucket IN ('minute', 'hour', 'day')),
  CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
  CHECK (range_start IS NULL OR range_end IS NULL OR range_start < range_end)
);

CREATE INDEX IF NOT EXISTS analytics_rollup_jobs_status_created_idx
  ON analytics_rollup_jobs(status, created_at ASC);

CREATE INDEX IF NOT EXISTS analytics_rollup_jobs_domain_created_idx
  ON analytics_rollup_jobs(domain_id, created_at DESC)
  WHERE domain_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS analytics_query_cache (
  cache_key TEXT PRIMARY KEY,
  domain_id TEXT NULL REFERENCES domains(id) ON DELETE CASCADE,
  query_hash TEXT NOT NULL,
  payload_json JSONB NOT NULL,
  etag TEXT NOT NULL,
  fresh_until BIGINT NOT NULL,
  stale_until BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS analytics_query_cache_domain_fresh_idx
  ON analytics_query_cache(domain_id, fresh_until DESC);

CREATE MATERIALIZED VIEW IF NOT EXISTS reporting_current_platform_summary AS
SELECT
  1 AS id,
  COUNT(DISTINCT d.id) FILTER (WHERE d.status = 'active') AS active_domains,
  COALESCE(SUM(u.requests_count) FILTER (WHERE u.ts >= EXTRACT(EPOCH FROM NOW())::BIGINT - 300), 0) AS requests_5m,
  COALESCE(SUM(u.requests_count) FILTER (WHERE UPPER(u.cache_status) = 'HIT' AND u.ts >= EXTRACT(EPOCH FROM NOW())::BIGINT - 300), 0) AS cache_hits_5m,
  COALESCE(MAX(u.ts), 0) AS latest_usage_ts,
  EXTRACT(EPOCH FROM NOW())::BIGINT AS refreshed_at
FROM domains d
LEFT JOIN usage_rollups u ON u.domain_id = d.id;

CREATE UNIQUE INDEX IF NOT EXISTS reporting_current_platform_summary_id_idx
  ON reporting_current_platform_summary(id);

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

CREATE TABLE IF NOT EXISTS recommendations (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  type TEXT NOT NULL,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  why TEXT NOT NULL,
  confidence INTEGER NOT NULL,
  risk TEXT NOT NULL,
  impact TEXT NOT NULL,
  preview_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  one_click_action JSONB NOT NULL DEFAULT '{}'::jsonb,
  status TEXT NOT NULL DEFAULT 'open',
  snoozed_until BIGINT NULL,
  dismissed_at BIGINT NULL,
  applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (confidence >= 0 AND confidence <= 100),
  CHECK (risk IN ('safe', 'moderate', 'risky')),
  CHECK (impact IN ('security', 'reliability', 'performance', 'ssl')),
  CHECK (status IN ('open', 'snoozed', 'dismissed', 'applied'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_recommendations_domain_type
  ON recommendations(domain_id, type);

CREATE INDEX IF NOT EXISTS idx_recommendations_status_domain
  ON recommendations(status, domain_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS domain_onboarding (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'not_started',
  answers_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  recommended_profile_key TEXT NOT NULL DEFAULT 'basic_website',
  skipped_at BIGINT NULL,
  completed_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (status IN ('not_started', 'in_progress', 'skipped', 'completed'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_domain_onboarding_domain
  ON domain_onboarding(domain_id);

CREATE INDEX IF NOT EXISTS idx_domain_onboarding_status
  ON domain_onboarding(status, updated_at DESC);

CREATE TABLE IF NOT EXISTS config_state (
  id SMALLINT PRIMARY KEY,
  version BIGINT NOT NULL,
  active_snapshot_version BIGINT NULL,
  CONSTRAINT config_state_singleton CHECK (id = 1)
);

INSERT INTO config_state (id, version) VALUES (1, 0)
ON CONFLICT (id) DO NOTHING;
ALTER TABLE config_state ADD COLUMN IF NOT EXISTS active_snapshot_version BIGINT NULL;

CREATE TABLE IF NOT EXISTS config_snapshots (
  version BIGINT PRIMARY KEY,
  content_hash TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  generated_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_config_snapshots_generated_version
  ON config_snapshots(generated_at DESC, version DESC);

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
  key_header_name TEXT NULL,
  requests_per_minute INTEGER NOT NULL,
  action TEXT NOT NULL DEFAULT 'block',
  challenge_difficulty INTEGER NULL CHECK (challenge_difficulty IS NULL OR (challenge_difficulty BETWEEN 1 AND 6)),
  profile_id TEXT NULL,
  intent_id TEXT NULL,
  template_key TEXT NULL,
  managed_by TEXT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE IF NOT EXISTS waiting_room_policies (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL UNIQUE REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT false,
  mode TEXT NOT NULL DEFAULT 'monitoring',
  state TEXT NOT NULL DEFAULT 'disabled',
  reason TEXT NULL,
  rps_threshold INTEGER NOT NULL DEFAULT 100,
  active_origin_threshold INTEGER NOT NULL DEFAULT 20,
  origin_latency_ms_threshold INTEGER NOT NULL DEFAULT 3000,
  origin_error_rate_threshold INTEGER NOT NULL DEFAULT 50,
  admission_rate_per_minute INTEGER NOT NULL DEFAULT 60,
  queue_limit INTEGER NOT NULL DEFAULT 1000,
  per_client_ticket_limit INTEGER NOT NULL DEFAULT 3,
  ticket_ttl_seconds INTEGER NOT NULL DEFAULT 300,
  admission_ttl_seconds INTEGER NOT NULL DEFAULT 900,
  status_poll_seconds INTEGER NOT NULL DEFAULT 5,
  jitter_seconds INTEGER NOT NULL DEFAULT 4,
  unhealthy_windows INTEGER NOT NULL DEFAULT 3,
  healthy_windows INTEGER NOT NULL DEFAULT 3,
  minimum_state_seconds INTEGER NOT NULL DEFAULT 60,
  recovery_ramp_percent INTEGER NOT NULL DEFAULT 25,
  manual_override_until BIGINT NULL,
  trusted_cidrs_json JSONB NOT NULL DEFAULT '[]'::jsonb,
  waiting_room_title TEXT NOT NULL DEFAULT 'Traffic is high',
  waiting_room_message TEXT NOT NULL DEFAULT 'You are in a short waiting room while this site protects its origin.',
  counters_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (mode IN ('monitoring','automatic','manual')),
  CHECK (state IN ('disabled','monitoring','healthy','entering_overload','overloaded','recovering','manual_emergency')),
  CHECK (rps_threshold BETWEEN 1 AND 1000000),
  CHECK (active_origin_threshold BETWEEN 1 AND 1000000),
  CHECK (origin_latency_ms_threshold BETWEEN 1 AND 600000),
  CHECK (origin_error_rate_threshold BETWEEN 1 AND 100),
  CHECK (admission_rate_per_minute BETWEEN 1 AND 1000000),
  CHECK (queue_limit BETWEEN 1 AND 1000000),
  CHECK (per_client_ticket_limit BETWEEN 1 AND 1000),
  CHECK (ticket_ttl_seconds BETWEEN 30 AND 3600),
  CHECK (admission_ttl_seconds BETWEEN 60 AND 86400),
  CHECK (status_poll_seconds BETWEEN 2 AND 300),
  CHECK (jitter_seconds BETWEEN 0 AND 300),
  CHECK (unhealthy_windows BETWEEN 1 AND 100),
  CHECK (healthy_windows BETWEEN 1 AND 100),
  CHECK (minimum_state_seconds BETWEEN 1 AND 86400),
  CHECK (recovery_ramp_percent BETWEEN 1 AND 100)
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
  challenge_difficulty INTEGER NULL CHECK (challenge_difficulty IS NULL OR (challenge_difficulty BETWEEN 1 AND 6)),
  description TEXT NULL,
  waf_group_id TEXT NULL,
  waf_severity TEXT NULL,
  waf_confidence TEXT NULL,
  waf_safe_reason TEXT NULL,
  bot_class TEXT NULL,
  bot_score INTEGER NULL,
  bot_action TEXT NULL,
  profile_id TEXT NULL,
  intent_id TEXT NULL,
  template_key TEXT NULL,
  managed_by TEXT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS verified_bot_sources (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  bot_class TEXT NOT NULL DEFAULT 'verified_search_bot',
  provider TEXT NOT NULL,
  user_agent_pattern TEXT NOT NULL,
  cidr TEXT NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (bot_class IN ('verified_search_bot', 'monitoring_tool', 'good_bot')),
  CHECK (provider <> ''),
  CHECK (user_agent_pattern <> ''),
  CHECK (cidr <> '')
);

CREATE INDEX IF NOT EXISTS idx_verified_bot_sources_domain_enabled
  ON verified_bot_sources(domain_id, enabled);

CREATE TABLE IF NOT EXISTS domain_header_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  operation TEXT NOT NULL,
  header_name TEXT NOT NULL,
  header_value TEXT NULL,
  path_pattern TEXT NOT NULL DEFAULT '/*',
  profile_id TEXT NULL,
  intent_id TEXT NULL,
  template_key TEXT NULL,
  managed_by TEXT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
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
  profile_id TEXT NULL,
  intent_id TEXT NULL,
  template_key TEXT NULL,
  managed_by TEXT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (rule_type IN ('allow', 'block'))
);

CREATE INDEX IF NOT EXISTS idx_domain_ip_rules_domain_type
  ON domain_ip_rules(domain_id, rule_type, created_at);

CREATE TABLE IF NOT EXISTS cache_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  path_prefix TEXT NOT NULL,
  ttl_seconds INTEGER NOT NULL,
  profile_id TEXT NULL,
  intent_id TEXT NULL,
  template_key TEXT NULL,
  managed_by TEXT NULL,
  user_modified BOOLEAN NOT NULL DEFAULT false,
  last_generated_at BIGINT NULL,
  last_applied_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

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
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS waf_group_id TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS waf_severity TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS waf_confidence TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS waf_safe_reason TEXT NULL;

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

CREATE TABLE IF NOT EXISTS domain_cache_settings (
  domain_id TEXT PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  default_edge_ttl_seconds INTEGER NOT NULL DEFAULT 3600,
  default_browser_ttl_seconds INTEGER NULL,
  cache_query_string_mode TEXT NOT NULL DEFAULT 'include_all',
  respect_origin_cache_control BOOLEAN NOT NULL DEFAULT true,
  cache_authorized_requests BOOLEAN NOT NULL DEFAULT false,
  stale_if_error_seconds INTEGER NOT NULL DEFAULT 86400,
  static_asset_cache_enabled BOOLEAN NOT NULL DEFAULT false,
  ignore_query_strings_for_static BOOLEAN NOT NULL DEFAULT false,
  bypass_logged_in_users BOOLEAN NOT NULL DEFAULT true,
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

CREATE INDEX IF NOT EXISTS idx_cache_purge_requests_domain_created
  ON cache_purge_requests(domain_id, created_at DESC);

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
ALTER TABLE domain_ssl_settings ALTER COLUMN auto_renew SET DEFAULT true;
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
CREATE INDEX IF NOT EXISTS idx_ssl_certificates_domain_not_after
  ON ssl_certificates(domain_id, not_after);
CREATE INDEX IF NOT EXISTS idx_ssl_renewal_history_domain
  ON ssl_renewal_history(domain_id, started_at DESC);

CREATE TABLE IF NOT EXISTS ssl_jobs (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  status TEXT NOT NULL,
  progress_percent INTEGER NOT NULL DEFAULT 0,
  message TEXT NOT NULL DEFAULT '',
  error_code TEXT NULL,
  error_detail TEXT NULL,
  hostnames_json TEXT NOT NULL DEFAULT '[]',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  finished_at BIGINT NULL,
  CHECK (status IN ('queued','checking_dns','creating_order','validating_challenge','issuing','installing','issued','failed','cancelled')),
  CHECK (progress_percent >= 0 AND progress_percent <= 100)
);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_domain_created
  ON ssl_jobs(domain_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_active
  ON ssl_jobs(domain_id, status)
  WHERE status IN ('queued','checking_dns','creating_order','validating_challenge','issuing','installing');

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_status_created
  ON ssl_jobs(status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ssl_jobs_created_status
  ON ssl_jobs(created_at DESC, status);

CREATE TABLE IF NOT EXISTS ssl_acme_accounts (
  id TEXT PRIMARY KEY,
  directory_url TEXT NOT NULL UNIQUE,
  kid TEXT NOT NULL,
  account_key_pem TEXT NOT NULL,
  contact_email TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
