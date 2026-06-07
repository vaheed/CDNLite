ALTER TABLE config_state
  ADD COLUMN IF NOT EXISTS active_snapshot_version BIGINT NULL;
