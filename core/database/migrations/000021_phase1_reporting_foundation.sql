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

CREATE INDEX IF NOT EXISTS idx_usage_rollups_domain_bucket_ts
  ON usage_rollups(domain_id, ((ts / 60) * 60) DESC, cache_status, status);

CREATE INDEX IF NOT EXISTS idx_usage_rollups_ts_brin
  ON usage_rollups USING BRIN(ts);

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
