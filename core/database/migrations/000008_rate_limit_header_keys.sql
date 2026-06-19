ALTER TABLE rate_limit_rules
  ADD COLUMN IF NOT EXISTS key_header_name TEXT NULL;
