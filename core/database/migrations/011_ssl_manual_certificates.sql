ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS certificate_pem TEXT NULL;
ALTER TABLE ssl_certificates ADD COLUMN IF NOT EXISTS private_key_pem TEXT NULL;
