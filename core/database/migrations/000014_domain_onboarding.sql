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
