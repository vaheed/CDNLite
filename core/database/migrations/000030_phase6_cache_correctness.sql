-- Phase 6 cache correctness controls. These fields make cache eligibility,
-- key variation, stale behavior, and debug output explicit in snapshots.
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS cache_methods_json TEXT NOT NULL DEFAULT '["GET","HEAD"]';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS cache_status_code_policy_json TEXT NOT NULL DEFAULT '{"200":true,"301":true,"302":true,"404":false,"500":false,"502":false,"503":false,"504":false}';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS bypass_headers_json TEXT NOT NULL DEFAULT '["authorization"]';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS bypass_cookies_json TEXT NOT NULL DEFAULT '["session","auth","wordpress_logged_in","laravel_session"]';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS vary_headers_json TEXT NOT NULL DEFAULT '["accept-encoding"]';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS cache_key_dimensions_json TEXT NOT NULL DEFAULT '{"scheme":true,"host":true,"path":true,"query":"include_all","headers":["accept-encoding"],"device":false,"country":false,"language":false,"domain_id":true,"rule_version":true}';
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS debug_headers_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS stale_while_revalidate_seconds INTEGER NOT NULL DEFAULT 0;
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS negative_ttl_seconds INTEGER NOT NULL DEFAULT 0;
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS max_object_size_bytes BIGINT NOT NULL DEFAULT 104857600;
