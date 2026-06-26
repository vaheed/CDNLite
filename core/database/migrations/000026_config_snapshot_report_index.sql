CREATE INDEX IF NOT EXISTS idx_config_snapshots_generated_version
  ON config_snapshots(generated_at DESC, version DESC);
