CREATE INDEX IF NOT EXISTS idx_domains_domain ON domains(domain);
CREATE INDEX IF NOT EXISTS idx_dns_records_domain_name_type ON dns_records(domain_id, name, type);
CREATE INDEX IF NOT EXISTS idx_redirect_rules_domain_enabled ON redirect_rules(domain_id, enabled);
CREATE INDEX IF NOT EXISTS idx_waf_rules_domain_enabled ON waf_rules(domain_id, enabled);
CREATE INDEX IF NOT EXISTS idx_cache_rules_domain_enabled ON cache_rules(domain_id, enabled);
CREATE INDEX IF NOT EXISTS idx_edge_request_nonces_expires ON edge_request_nonces(expires_at);
CREATE INDEX IF NOT EXISTS idx_usage_aggregates_lookup ON usage_aggregates(domain_id, bucket, bucket_ts);
