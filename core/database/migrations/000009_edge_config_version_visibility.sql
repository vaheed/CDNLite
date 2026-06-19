ALTER TABLE edge_nodes
  ADD COLUMN IF NOT EXISTS applied_config_version INTEGER NULL,
  ADD COLUMN IF NOT EXISTS last_config_pull_at BIGINT NULL,
  ADD COLUMN IF NOT EXISTS config_apply_error TEXT NULL;

