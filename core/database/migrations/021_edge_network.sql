ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS geo_enabled BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE edge_nodes ADD COLUMN IF NOT EXISTS anycast_enabled BOOLEAN NOT NULL DEFAULT false;

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
