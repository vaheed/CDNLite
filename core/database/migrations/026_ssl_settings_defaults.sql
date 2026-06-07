ALTER TABLE domain_ssl_settings
  ALTER COLUMN auto_renew SET DEFAULT false;

UPDATE domain_ssl_settings
SET auto_renew = false
WHERE auto_renew = true;
