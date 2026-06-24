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
