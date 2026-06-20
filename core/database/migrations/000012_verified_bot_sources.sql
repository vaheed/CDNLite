-- Phase 13: publish operator-curated verified bot sources to edge config.
-- The edge requires both CIDR and User-Agent match before bypassing fake-bot challenges.
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
