-- Phase 15 safe cache controls. Defaults preserve existing cache behaviour.
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS static_asset_cache_enabled BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS ignore_query_strings_for_static BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE domain_cache_settings
  ADD COLUMN IF NOT EXISTS bypass_logged_in_users BOOLEAN NOT NULL DEFAULT true;
