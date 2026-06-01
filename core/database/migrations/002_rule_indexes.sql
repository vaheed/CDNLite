CREATE INDEX IF NOT EXISTS idx_sites_domain ON sites(domain);
CREATE INDEX IF NOT EXISTS idx_dns_records_site_name_type ON dns_records(site_id, name, type);
CREATE INDEX IF NOT EXISTS idx_redirect_rules_site_enabled ON redirect_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_waf_rules_site_enabled ON waf_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_cache_rules_site_enabled ON cache_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_edge_request_nonces_expires ON edge_request_nonces(expires_at);
CREATE INDEX IF NOT EXISTS idx_usage_aggregates_lookup ON usage_aggregates(site_id, bucket, bucket_ts);
