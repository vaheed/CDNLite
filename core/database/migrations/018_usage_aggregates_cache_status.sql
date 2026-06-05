ALTER TABLE usage_aggregates ADD COLUMN IF NOT EXISTS cache_status TEXT NOT NULL DEFAULT 'UNKNOWN';

DO $$
DECLARE
  constraint_name text;
BEGIN
  FOR constraint_name IN
    SELECT c.conname
    FROM pg_constraint c
    JOIN pg_class rel ON rel.oid = c.conrelid
    WHERE rel.relname = 'usage_aggregates'
      AND c.contype = 'u'
      AND (
        SELECT array_agg(att.attname ORDER BY u.ord)
        FROM unnest(c.conkey) WITH ORDINALITY AS u(attnum, ord)
        JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = u.attnum
      ) = ARRAY['bucket', 'bucket_ts', 'domain_id', 'edge_node_id', 'status']
  LOOP
    EXECUTE format('ALTER TABLE usage_aggregates DROP CONSTRAINT %I', constraint_name);
  END LOOP;
END $$;

ALTER TABLE usage_aggregates
  ADD CONSTRAINT usage_aggregates_bucket_ts_domain_id_edge_node_id_status_cache_status_key
  UNIQUE (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status);
