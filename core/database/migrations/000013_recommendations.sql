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
