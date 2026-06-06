ALTER TABLE dns_records
  ADD COLUMN IF NOT EXISTS routing_policy TEXT NOT NULL DEFAULT 'standard';
ALTER TABLE dns_records
  ADD COLUMN IF NOT EXISTS canonical_edge_hostname TEXT NULL;

ALTER TABLE dns_records
  DROP CONSTRAINT IF EXISTS dns_records_routing_policy_check;
ALTER TABLE dns_records
  ADD CONSTRAINT dns_records_routing_policy_check
  CHECK (routing_policy IN ('standard', 'geo', 'anycast', 'geo_anycast'));

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

ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS proxy_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS dns_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS cache_enabled BOOLEAN NOT NULL DEFAULT true;
