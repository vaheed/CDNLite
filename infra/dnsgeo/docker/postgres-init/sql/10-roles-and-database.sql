\set ON_ERROR_STOP on

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pdns') THEN
    CREATE ROLE pdns LOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'poweradmin') THEN
    CREATE ROLE poweradmin LOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'replicator') THEN
    CREATE ROLE replicator WITH REPLICATION LOGIN;
  END IF;
END
$$;

ALTER ROLE pdns WITH LOGIN PASSWORD :'pdns_password';
ALTER ROLE poweradmin WITH LOGIN PASSWORD :'poweradmin_password';
ALTER ROLE replicator WITH REPLICATION LOGIN PASSWORD :'replication_password';
SELECT 'CREATE DATABASE pdns OWNER poweradmin'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'pdns')
\gexec

ALTER DATABASE pdns OWNER TO poweradmin;
