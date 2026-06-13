\set ON_ERROR_STOP on

CREATE SEQUENCE IF NOT EXISTS log_users_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS log_users (
  id integer DEFAULT nextval('log_users_id_seq1') NOT NULL PRIMARY KEY,
  event character varying(2048),
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  priority integer
);

CREATE SEQUENCE IF NOT EXISTS log_api_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS log_api (
  id integer DEFAULT nextval('log_api_id_seq1') NOT NULL PRIMARY KEY,
  event character varying(2048),
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  priority integer
);

CREATE SEQUENCE IF NOT EXISTS log_zones_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS log_zones (
  id integer DEFAULT nextval('log_zones_id_seq1') NOT NULL PRIMARY KEY,
  event character varying(2048),
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  priority integer,
  zone_id integer
);
CREATE INDEX IF NOT EXISTS idx_log_zones_zone_id ON log_zones USING btree (zone_id);

CREATE SEQUENCE IF NOT EXISTS log_groups_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS log_groups (
  id integer DEFAULT nextval('log_groups_id_seq1') NOT NULL PRIMARY KEY,
  event character varying(2048),
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  priority integer,
  group_id integer
);
CREATE INDEX IF NOT EXISTS idx_log_groups_group_id ON log_groups USING btree (group_id);

CREATE SEQUENCE IF NOT EXISTS perm_items_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS perm_items (
  id integer DEFAULT nextval('perm_items_id_seq') NOT NULL PRIMARY KEY,
  name character varying(64),
  descr character varying(1024)
);

INSERT INTO perm_items (id, name, descr) VALUES
  (41, 'zone_master_add', 'User is allowed to add new master zones.'),
  (42, 'zone_slave_add', 'User is allowed to add new slave zones.'),
  (43, 'zone_content_view_own', 'User is allowed to see the content and meta data of zones he owns.'),
  (44, 'zone_content_edit_own', 'User is allowed to edit the content of zones he owns.'),
  (45, 'zone_meta_edit_own', 'User is allowed to edit the meta data of zones he owns.'),
  (46, 'zone_content_view_others', 'User is allowed to see the content and meta data of zones he does not own.'),
  (47, 'zone_content_edit_others', 'User is allowed to edit the content of zones he does not own.'),
  (48, 'zone_meta_edit_others', 'User is allowed to edit the meta data of zones he does not own.'),
  (49, 'search', 'User is allowed to perform searches.'),
  (50, 'supermaster_view', 'User is allowed to view supermasters.'),
  (51, 'supermaster_add', 'User is allowed to add new supermasters.'),
  (52, 'supermaster_edit', 'User is allowed to edit supermasters.'),
  (53, 'user_is_ueberuser', 'User has full access. God-like. Redeemer.'),
  (54, 'user_view_others', 'User is allowed to see other users and their details.'),
  (55, 'user_add_new', 'User is allowed to add new users.'),
  (56, 'user_edit_own', 'User is allowed to edit their own details.'),
  (57, 'user_edit_others', 'User is allowed to edit other users.'),
  (58, 'user_passwd_edit_others', 'User is allowed to edit the password of other users.'),
  (59, 'user_edit_templ_perm', 'User is allowed to change the permission template that is assigned to a user.'),
  (60, 'templ_perm_add', 'User is allowed to add new permission templates.'),
  (61, 'templ_perm_edit', 'User is allowed to edit existing permission templates.'),
  (62, 'zone_content_edit_own_as_client', 'User is allowed to edit record, but not SOA and NS.'),
  (63, 'zone_templ_add', 'User is allowed to add new zone templates.'),
  (64, 'zone_templ_edit', 'User is allowed to edit existing zone templates.'),
  (65, 'api_manage_keys', 'User is allowed to create and manage API keys.'),
  (67, 'zone_delete_own', 'User is allowed to delete zones they own.'),
  (68, 'zone_delete_others', 'User is allowed to delete zones owned by others.'),
  (69, 'user_enforce_mfa', 'User is required to use multi-factor authentication.')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;
SELECT setval('perm_items_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM perm_items), 1));

CREATE SEQUENCE IF NOT EXISTS perm_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS perm_templ (
  id integer DEFAULT nextval('perm_templ_id_seq') NOT NULL PRIMARY KEY,
  name character varying(128),
  descr character varying(1024),
  template_type character varying(10) DEFAULT 'user' NOT NULL,
  CONSTRAINT perm_templ_template_type_check CHECK (template_type IN ('user', 'group'))
);
ALTER TABLE perm_templ ADD COLUMN IF NOT EXISTS template_type character varying(10) DEFAULT 'user' NOT NULL;

INSERT INTO perm_templ (id, name, descr, template_type) VALUES
  (1, 'Administrator', 'Administrator template with full rights.', 'user'),
  (2, 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.', 'user'),
  (3, 'Editor', 'Edit own zone records but cannot modify SOA and NS records.', 'user'),
  (4, 'Viewer', 'Read-only access to own zones with search capability.', 'user'),
  (5, 'Guest', 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.', 'user'),
  (6, 'Administrators', 'Full administrative access for group members.', 'group'),
  (7, 'Zone Managers', 'Full zone management for group members.', 'group'),
  (8, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 'group'),
  (9, 'Viewers', 'Read-only zone access for group members.', 'group'),
  (10, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;
SELECT setval('perm_templ_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM perm_templ), 1));

CREATE SEQUENCE IF NOT EXISTS perm_templ_items_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS perm_templ_items (
  id integer DEFAULT nextval('perm_templ_items_id_seq') NOT NULL PRIMARY KEY,
  templ_id integer,
  perm_id integer
);
CREATE INDEX IF NOT EXISTS idx_perm_templ_items_templ_id ON perm_templ_items USING btree (templ_id);
CREATE INDEX IF NOT EXISTS idx_perm_templ_items_perm_id ON perm_templ_items USING btree (perm_id);

INSERT INTO perm_templ_items (id, templ_id, perm_id) VALUES
  (1, 1, 53),
  (2, 2, 41), (3, 2, 42), (4, 2, 43), (5, 2, 44), (6, 2, 45), (7, 2, 49), (8, 2, 56), (9, 2, 63), (10, 2, 64), (11, 2, 65), (12, 2, 67),
  (13, 3, 43), (14, 3, 49), (15, 3, 56), (16, 3, 62),
  (17, 4, 43), (18, 4, 49),
  (19, 6, 53),
  (20, 7, 41), (21, 7, 42), (22, 7, 43), (23, 7, 44), (24, 7, 45), (25, 7, 49), (26, 7, 56), (27, 7, 63), (28, 7, 64), (29, 7, 65), (30, 7, 67),
  (31, 8, 43), (32, 8, 49), (33, 8, 56), (34, 8, 62),
  (35, 9, 43), (36, 9, 49)
ON CONFLICT (id) DO UPDATE SET templ_id = EXCLUDED.templ_id, perm_id = EXCLUDED.perm_id;
SELECT setval('perm_templ_items_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM perm_templ_items), 1));

CREATE SEQUENCE IF NOT EXISTS records_zone_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS records_zone_templ (
  id integer DEFAULT nextval('records_zone_templ_id_seq') NOT NULL PRIMARY KEY,
  domain_id integer,
  record_id integer,
  zone_templ_id integer
);
ALTER SEQUENCE records_zone_templ_id_seq OWNED BY records_zone_templ.id;
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_domain_id ON records_zone_templ USING btree (domain_id);
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_zone_templ_id ON records_zone_templ USING btree (zone_templ_id);

CREATE SEQUENCE IF NOT EXISTS users_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS users (
  id integer DEFAULT nextval('users_id_seq') NOT NULL PRIMARY KEY,
  username character varying(64),
  password character varying(128),
  fullname character varying(255),
  email character varying(255),
  description character varying(1024),
  perm_templ integer,
  perm_templ_source character varying(20) NOT NULL DEFAULT 'admin',
  active integer,
  use_ldap integer,
  auth_method character varying(20) DEFAULT 'sql' NOT NULL
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS perm_templ_source character varying(20) NOT NULL DEFAULT 'admin';
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_method character varying(20) DEFAULT 'sql' NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_perm_templ ON users USING btree (perm_templ);

CREATE SEQUENCE IF NOT EXISTS login_attempts_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS login_attempts (
  id integer DEFAULT nextval('login_attempts_id_seq') NOT NULL PRIMARY KEY,
  user_id integer NULL,
  ip_address character varying(45) NOT NULL,
  timestamp integer NOT NULL,
  successful boolean NOT NULL,
  CONSTRAINT fk_login_attempts_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_user_id ON login_attempts USING btree (user_id);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_address ON login_attempts USING btree (ip_address);
CREATE INDEX IF NOT EXISTS idx_login_attempts_timestamp ON login_attempts USING btree (timestamp);

CREATE SEQUENCE IF NOT EXISTS zone_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS zone_templ (
  id integer DEFAULT nextval('zone_templ_id_seq') NOT NULL PRIMARY KEY,
  name character varying(128),
  descr character varying(1024),
  owner integer,
  created_by integer,
  is_default boolean DEFAULT false NOT NULL,
  CONSTRAINT fk_zone_templ_users FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
ALTER TABLE zone_templ ADD COLUMN IF NOT EXISTS created_by integer;
ALTER TABLE zone_templ ADD COLUMN IF NOT EXISTS is_default boolean DEFAULT false NOT NULL;
CREATE INDEX IF NOT EXISTS idx_zone_templ_owner ON zone_templ USING btree (owner);
CREATE INDEX IF NOT EXISTS idx_zone_templ_created_by ON zone_templ USING btree (created_by);

CREATE SEQUENCE IF NOT EXISTS zone_templ_records_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS zone_templ_records (
  id integer DEFAULT nextval('zone_templ_records_id_seq') NOT NULL PRIMARY KEY,
  zone_templ_id integer,
  name character varying(255),
  type character varying(6),
  content character varying(2048),
  ttl integer,
  prio integer
);
ALTER TABLE zone_templ_records ALTER COLUMN content TYPE character varying(2048);
CREATE INDEX IF NOT EXISTS idx_zone_templ_records_zone_templ_id ON zone_templ_records USING btree (zone_templ_id);

CREATE SEQUENCE IF NOT EXISTS zones_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS zones (
  id integer DEFAULT nextval('zones_id_seq') NOT NULL PRIMARY KEY,
  domain_id integer,
  owner integer DEFAULT NULL,
  comment character varying(1024),
  zone_templ_id integer,
  zone_name character varying(255) DEFAULT NULL,
  zone_type character varying(8) DEFAULT NULL,
  zone_master character varying(255) DEFAULT NULL
);
ALTER TABLE zones ADD COLUMN IF NOT EXISTS zone_name character varying(255) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN IF NOT EXISTS zone_type character varying(8) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN IF NOT EXISTS zone_master character varying(255) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_zones_domain_id ON zones USING btree (domain_id);
CREATE INDEX IF NOT EXISTS idx_zones_owner ON zones USING btree (owner);
CREATE INDEX IF NOT EXISTS idx_zones_zone_templ_id ON zones USING btree (zone_templ_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_zones_zone_name ON zones USING btree (zone_name);

CREATE SEQUENCE IF NOT EXISTS api_keys_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS api_keys (
  id integer DEFAULT nextval('api_keys_id_seq') NOT NULL PRIMARY KEY,
  name character varying(255) NOT NULL,
  secret_key character varying(255) NOT NULL,
  created_by integer,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
  last_used_at timestamp,
  disabled boolean DEFAULT false NOT NULL,
  expires_at timestamp,
  CONSTRAINT fk_api_keys_users FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_api_keys_secret_key ON api_keys USING btree (secret_key);
CREATE INDEX IF NOT EXISTS idx_api_keys_created_by ON api_keys USING btree (created_by);
CREATE INDEX IF NOT EXISTS idx_api_keys_disabled ON api_keys USING btree (disabled);

CREATE SEQUENCE IF NOT EXISTS user_mfa_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;
CREATE TABLE IF NOT EXISTS user_mfa (
  id integer DEFAULT nextval('user_mfa_id_seq') NOT NULL PRIMARY KEY,
  user_id integer NOT NULL,
  enabled boolean DEFAULT false NOT NULL,
  secret character varying(255),
  recovery_codes text,
  type character varying(20) DEFAULT 'app' NOT NULL,
  last_used_at timestamp,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
  updated_at timestamp,
  verification_data text,
  CONSTRAINT fk_user_mfa_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_mfa_user_id ON user_mfa USING btree (user_id);
CREATE INDEX IF NOT EXISTS idx_user_mfa_enabled ON user_mfa USING btree (enabled);

CREATE TABLE IF NOT EXISTS user_preferences (
  id serial NOT NULL PRIMARY KEY,
  user_id integer NOT NULL,
  preference_key character varying(100) NOT NULL,
  preference_value text,
  CONSTRAINT fk_user_preferences_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_preferences_user_key ON user_preferences USING btree (user_id, preference_key);
CREATE INDEX IF NOT EXISTS idx_user_preferences_user_id ON user_preferences USING btree (user_id);

CREATE TABLE IF NOT EXISTS zone_template_sync (
  id serial NOT NULL PRIMARY KEY,
  zone_id integer NOT NULL,
  zone_templ_id integer NOT NULL,
  last_synced timestamp,
  template_last_modified timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  needs_sync boolean NOT NULL DEFAULT false,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zone_template_sync_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  CONSTRAINT fk_zone_template_sync_templ FOREIGN KEY (zone_templ_id) REFERENCES zone_templ(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_zone_template_unique ON zone_template_sync USING btree (zone_id, zone_templ_id);
CREATE INDEX IF NOT EXISTS idx_zone_templ_id ON zone_template_sync USING btree (zone_templ_id);
CREATE INDEX IF NOT EXISTS idx_needs_sync ON zone_template_sync USING btree (needs_sync);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id SERIAL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token VARCHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used BOOLEAN NOT NULL DEFAULT FALSE,
  ip_address VARCHAR(45) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_prt_expires ON password_reset_tokens(expires_at);

CREATE TABLE IF NOT EXISTS username_recovery_requests (
  id SERIAL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_urr_email ON username_recovery_requests(email);
CREATE INDEX IF NOT EXISTS idx_urr_ip ON username_recovery_requests(ip_address);
CREATE INDEX IF NOT EXISTS idx_urr_created ON username_recovery_requests(created_at);

CREATE TABLE IF NOT EXISTS user_agreements (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  agreement_version VARCHAR(50) NOT NULL,
  accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  CONSTRAINT fk_user_agreements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS unique_user_agreement ON user_agreements(user_id, agreement_version);
CREATE INDEX IF NOT EXISTS idx_user_agreements_user_id ON user_agreements(user_id);
CREATE INDEX IF NOT EXISTS idx_user_agreements_version ON user_agreements(agreement_version);

CREATE TABLE IF NOT EXISTS oidc_user_links (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  provider_id VARCHAR(50) NOT NULL,
  oidc_subject VARCHAR(255) NOT NULL,
  username VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, provider_id),
  UNIQUE (oidc_subject, provider_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_oidc_subject ON oidc_user_links(oidc_subject);

CREATE TABLE IF NOT EXISTS saml_user_links (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  provider_id VARCHAR(50) NOT NULL,
  saml_subject VARCHAR(255) NOT NULL,
  username VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, provider_id),
  UNIQUE (saml_subject, provider_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_saml_provider_id ON saml_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_saml_subject ON saml_user_links(saml_subject);

CREATE TABLE IF NOT EXISTS user_groups (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  description TEXT,
  perm_templ INTEGER NOT NULL REFERENCES perm_templ(id),
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_groups_perm_templ ON user_groups(perm_templ);
CREATE INDEX IF NOT EXISTS idx_user_groups_created_by ON user_groups(created_by);
CREATE INDEX IF NOT EXISTS idx_user_groups_name ON user_groups(name);

CREATE OR REPLACE FUNCTION update_user_groups_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_user_groups_updated_at ON user_groups;
CREATE TRIGGER trigger_user_groups_updated_at
BEFORE UPDATE ON user_groups
FOR EACH ROW EXECUTE FUNCTION update_user_groups_updated_at();

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
  (1, 'Administrators', 'Full administrative access to all system functions.', 6, NULL),
  (2, 'Zone Managers', 'Full zone management including creation, editing, and deletion.', 7, NULL),
  (3, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 8, NULL),
  (4, 'Viewers', 'Read-only access to zones with search capability.', 9, NULL),
  (5, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 10, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, perm_templ = EXCLUDED.perm_templ;
SELECT setval('user_groups_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM user_groups), 1));

CREATE TABLE IF NOT EXISTS user_group_members (
  id SERIAL PRIMARY KEY,
  group_id INTEGER NOT NULL REFERENCES user_groups(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (group_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_user_group_members_user ON user_group_members(user_id);
CREATE INDEX IF NOT EXISTS idx_user_group_members_group ON user_group_members(group_id);

CREATE TABLE IF NOT EXISTS zones_groups (
  id SERIAL PRIMARY KEY,
  domain_id INTEGER NOT NULL,
  group_id INTEGER NOT NULL REFERENCES user_groups(id) ON DELETE CASCADE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (domain_id, group_id)
);
CREATE INDEX IF NOT EXISTS idx_zones_groups_domain ON zones_groups(domain_id);
CREATE INDEX IF NOT EXISTS idx_zones_groups_group ON zones_groups(group_id);

CREATE TABLE IF NOT EXISTS record_comment_links (
  record_id INTEGER NOT NULL PRIMARY KEY,
  comment_id INTEGER NOT NULL UNIQUE
);
CREATE INDEX IF NOT EXISTS idx_record_comment_links_comment ON record_comment_links(comment_id);

DO $$
DECLARE
  rel regclass;
BEGIN
  -- PostgreSQL rejects direct owner changes on SERIAL/identity sequences
  -- that are linked to table columns. Change table ownership first; owned
  -- sequences follow their owning tables automatically. Only standalone
  -- sequences are altered directly.
  FOR rel IN
    SELECT c.oid::regclass
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind IN ('r', 'p')
      AND c.relname IN (
        'log_users','log_api','log_zones','log_groups',
        'perm_items','perm_templ','perm_templ_items',
        'records_zone_templ','users','login_attempts',
        'zone_templ','zone_templ_records','zones',
        'api_keys','user_mfa','user_preferences',
        'zone_template_sync','password_reset_tokens',
        'username_recovery_requests','user_agreements',
        'oidc_user_links','saml_user_links',
        'user_groups','user_group_members','zones_groups',
        'record_comment_links'
      )
  LOOP
    EXECUTE format('ALTER TABLE %s OWNER TO poweradmin', rel);
  END LOOP;

  FOR rel IN
    SELECT c.oid::regclass
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind = 'S'
      AND c.relname IN (
        'log_users_id_seq1','log_api_id_seq1','log_zones_id_seq1','log_groups_id_seq1',
        'perm_items_id_seq','perm_templ_id_seq','perm_templ_items_id_seq',
        'records_zone_templ_id_seq','users_id_seq','login_attempts_id_seq',
        'zone_templ_id_seq','zone_templ_records_id_seq','zones_id_seq',
        'api_keys_id_seq','user_mfa_id_seq','user_preferences_id_seq',
        'zone_template_sync_id_seq','password_reset_tokens_id_seq',
        'username_recovery_requests_id_seq','user_agreements_id_seq',
        'oidc_user_links_id_seq','saml_user_links_id_seq',
        'user_groups_id_seq','user_group_members_id_seq','zones_groups_id_seq'
      )
      AND NOT EXISTS (
        SELECT 1
        FROM pg_depend d
        WHERE d.objid = c.oid
          AND d.classid = 'pg_class'::regclass
          AND d.deptype IN ('a', 'i')
      )
  LOOP
    EXECUTE format('ALTER SEQUENCE %s OWNER TO poweradmin', rel);
  END LOOP;
END
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO poweradmin;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO poweradmin;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pdns;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO pdns;
