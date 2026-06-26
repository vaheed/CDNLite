CREATE INDEX IF NOT EXISTS idx_usage_aggregates_bucket_ts
  ON usage_aggregates(bucket, bucket_ts DESC);

CREATE INDEX IF NOT EXISTS idx_usage_aggregates_domain_bucket_ts
  ON usage_aggregates(domain_id, bucket, bucket_ts DESC);
