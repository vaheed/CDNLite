ALTER TABLE config_snapshots DROP CONSTRAINT IF EXISTS config_snapshots_content_hash_key;

DROP INDEX IF EXISTS config_snapshots_content_hash_key;
