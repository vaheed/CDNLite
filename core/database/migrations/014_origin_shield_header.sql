ALTER TABLE sites ADD COLUMN IF NOT EXISTS origin_shield_header_name TEXT NULL;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS origin_shield_header_value_hash TEXT NULL;
