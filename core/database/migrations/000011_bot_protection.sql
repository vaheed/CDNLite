-- Phase 13: keep bot policy intent with the WAF rule that enforces it.
-- These fields are nullable so ordinary, operator-created WAF rules remain unchanged.
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS bot_class TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS bot_score INTEGER NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS bot_action TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_waf_rules_bot_class ON waf_rules(domain_id, bot_class)
  WHERE bot_class IS NOT NULL;
