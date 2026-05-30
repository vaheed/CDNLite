CREATE TABLE IF NOT EXISTS sites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  domain TEXT NOT NULL UNIQUE,
  origin_scheme TEXT NOT NULL,
  origin_host TEXT NOT NULL,
  origin_port INTEGER NOT NULL,
  proxy_enabled INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS dns_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  name TEXT NOT NULL,
  content TEXT NOT NULL,
  ttl INTEGER NOT NULL,
  priority INTEGER,
  proxied INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS edge_nodes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  edge_id TEXT NOT NULL UNIQUE,
  hostname TEXT NOT NULL,
  public_ip TEXT NOT NULL,
  region TEXT NOT NULL,
  version TEXT NOT NULL,
  status TEXT NOT NULL,
  last_heartbeat INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS edge_tokens (
  edge_id TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_rollups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts INTEGER NOT NULL,
  site_id INTEGER NOT NULL,
  edge_node_id TEXT NOT NULL,
  requests_count INTEGER NOT NULL,
  bytes_in INTEGER NOT NULL,
  bytes_out INTEGER NOT NULL,
  status INTEGER NOT NULL,
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS usage_ingest_keys (
  idempotency_key TEXT PRIMARY KEY,
  item_count INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS config_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  version INTEGER NOT NULL
);

INSERT OR IGNORE INTO config_state (id, version) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS config_snapshots (
  version INTEGER PRIMARY KEY,
  content_hash TEXT NOT NULL UNIQUE,
  payload_json TEXT NOT NULL,
  generated_at INTEGER NOT NULL
);
