ALTER TABLE waf_rules
  ADD COLUMN IF NOT EXISTS challenge_difficulty INTEGER NULL;

ALTER TABLE rate_limit_rules
  ADD COLUMN IF NOT EXISTS challenge_difficulty INTEGER NULL;

ALTER TABLE waf_rules
  DROP CONSTRAINT IF EXISTS waf_rules_challenge_difficulty_range;

ALTER TABLE waf_rules
  ADD CONSTRAINT waf_rules_challenge_difficulty_range
  CHECK (challenge_difficulty IS NULL OR (challenge_difficulty BETWEEN 1 AND 6));

ALTER TABLE rate_limit_rules
  DROP CONSTRAINT IF EXISTS rate_limit_rules_challenge_difficulty_range;

ALTER TABLE rate_limit_rules
  ADD CONSTRAINT rate_limit_rules_challenge_difficulty_range
  CHECK (challenge_difficulty IS NULL OR (challenge_difficulty BETWEEN 1 AND 6));
