ALTER TABLE domain_origins
  ADD COLUMN IF NOT EXISTS load_balancing_algorithm TEXT NOT NULL DEFAULT 'weighted_hash',
  ADD COLUMN IF NOT EXISTS connection_timeout_seconds INTEGER NOT NULL DEFAULT 5,
  ADD COLUMN IF NOT EXISTS response_timeout_seconds INTEGER NOT NULL DEFAULT 30,
  ADD COLUMN IF NOT EXISTS retry_attempts INTEGER NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS retry_budget_per_minute INTEGER NOT NULL DEFAULT 60,
  ADD COLUMN IF NOT EXISTS circuit_breaker_enabled BOOLEAN NOT NULL DEFAULT true,
  ADD COLUMN IF NOT EXISTS circuit_failure_threshold INTEGER NOT NULL DEFAULT 5,
  ADD COLUMN IF NOT EXISTS circuit_recovery_seconds INTEGER NOT NULL DEFAULT 30,
  ADD COLUMN IF NOT EXISTS max_concurrent_requests INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS drain BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS shield_enabled BOOLEAN NOT NULL DEFAULT false;

UPDATE domain_origins SET role = 'primary' WHERE role = 'origin';

ALTER TABLE domain_origins ALTER COLUMN role SET DEFAULT 'primary';

ALTER TABLE domain_origins
  DROP CONSTRAINT IF EXISTS domain_origins_role_check,
  ADD CONSTRAINT domain_origins_role_check CHECK (role IN ('primary', 'backup', 'shield'));

ALTER TABLE domain_origins
  ADD CONSTRAINT domain_origins_load_balancing_algorithm_check CHECK (load_balancing_algorithm IN ('weighted_hash', 'consistent_hash')),
  ADD CONSTRAINT domain_origins_connection_timeout_seconds_check CHECK (connection_timeout_seconds BETWEEN 1 AND 60),
  ADD CONSTRAINT domain_origins_response_timeout_seconds_check CHECK (response_timeout_seconds BETWEEN 1 AND 600),
  ADD CONSTRAINT domain_origins_retry_attempts_check CHECK (retry_attempts BETWEEN 0 AND 3),
  ADD CONSTRAINT domain_origins_retry_budget_per_minute_check CHECK (retry_budget_per_minute BETWEEN 0 AND 100000),
  ADD CONSTRAINT domain_origins_circuit_failure_threshold_check CHECK (circuit_failure_threshold BETWEEN 1 AND 1000),
  ADD CONSTRAINT domain_origins_circuit_recovery_seconds_check CHECK (circuit_recovery_seconds BETWEEN 1 AND 3600),
  ADD CONSTRAINT domain_origins_max_concurrent_requests_check CHECK (max_concurrent_requests BETWEEN 0 AND 1000000);

CREATE INDEX IF NOT EXISTS domain_origins_phase7_routing_idx
  ON domain_origins (domain_id, enabled, role, drain, health_status, weight, id);

CREATE TABLE IF NOT EXISTS origin_health_observations (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  origin_id TEXT NOT NULL REFERENCES domain_origins(id) ON DELETE CASCADE,
  edge_node_id TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'unknown',
  reason TEXT NULL,
  upstream_status TEXT NULL,
  latency_ms INTEGER NULL,
  jitter_ms INTEGER NULL,
  sample_count INTEGER NOT NULL DEFAULT 1,
  first_observed_at BIGINT NOT NULL,
  last_observed_at BIGINT NOT NULL,
  last_success_at BIGINT NULL,
  last_failure_at BIGINT NULL,
  CHECK (status IN ('healthy', 'unhealthy', 'slow', 'unknown')),
  CHECK (sample_count BETWEEN 1 AND 1000000000),
  CHECK (latency_ms IS NULL OR latency_ms BETWEEN 0 AND 600000),
  CHECK (jitter_ms IS NULL OR jitter_ms BETWEEN 0 AND 600000)
);

CREATE UNIQUE INDEX IF NOT EXISTS origin_health_observations_edge_origin_idx
  ON origin_health_observations (domain_id, origin_id, edge_node_id);

CREATE INDEX IF NOT EXISTS origin_health_observations_domain_status_idx
  ON origin_health_observations (domain_id, status, last_observed_at DESC);
