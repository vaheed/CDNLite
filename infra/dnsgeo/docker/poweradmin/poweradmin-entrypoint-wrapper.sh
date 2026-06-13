#!/bin/bash
set -euo pipefail

ORIGINAL_ENTRYPOINT="/usr/local/bin/docker-entrypoint.sh"

log() {
  printf '[%s] [poweradmin-wrapper] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

lower() {
  printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]'
}

read_secret_for_wait() {
  local var_name="$1"
  local file_var="${var_name}__FILE"
  if [ -n "${!var_name:-}" ]; then
    printf '%s' "${!var_name}"
  elif [ -n "${!file_var:-}" ] && [ -r "${!file_var}" ]; then
    cat "${!file_var}"
  else
    printf ''
  fi
}

configure_poweradmin_https() {
  if [ -z "${POWERADMIN_FQDN:-}" ]; then
    return 0
  fi

  log "POWERADMIN_FQDN is set; configuring Caddy for automatic HTTPS for ${POWERADMIN_FQDN}"
  cat > /etc/caddy/Caddyfile <<'CADDYEOF'
{
    frankenphp
    admin off
    order php_server before file_server
    email {$ACME_EMAIL}
}

{$POWERADMIN_FQDN} {
    root * /app
    encode gzip

    @denied path /config* /lib* /tests* /vendor*
    @bootstrap path /vendor/twbs/bootstrap* /vendor/twbs/bootstrap-icons*

    handle @bootstrap {
        file_server
    }

    handle @denied {
        respond "Forbidden" 403
    }

    @hidden path .* *.sql *.md *.log *.yaml *.yml
    handle @hidden {
        respond "Forbidden" 403
    }

    @static path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
    handle @static {
        header Cache-Control "public, max-age=31536000"
        file_server
    }

    @options method OPTIONS
    handle @options {
        header Access-Control-Allow-Origin "*"
        header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        header Access-Control-Max-Age "3600"
        respond "" 204
    }

    @api path /api*
    handle @api {
        header Access-Control-Allow-Origin "*"
        header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        header Access-Control-Max-Age "3600"
        rewrite * /index.php{uri}
        php_server {
            env HTTP_AUTHORIZATION {http.request.header.Authorization}
        }
    }

    try_files {path} {path}/ /index.php{uri}
    php_server {
        env HTTP_AUTHORIZATION {http.request.header.Authorization}
    }
}
CADDYEOF
}

wait_for_database_schema() {
  local db_type db_host db_port db_user db_name db_pass ssl_mode ssl_args timeout elapsed conn

  db_type="${DB_TYPE:-sqlite}"
  if [ "$db_type" != "pgsql" ]; then
    return 0
  fi

  db_host="${DB_HOST:?DB_HOST is required}"
  db_port="${DB_PORT:-5432}"
  db_user="${DB_USER:?DB_USER is required}"
  db_name="${DB_NAME:?DB_NAME is required}"
  db_pass="$(read_secret_for_wait DB_PASS)"
  timeout="${POWERADMIN_DB_WAIT_TIMEOUT_SECONDS:-300}"

  ssl_mode="disable"
  if [ "$(lower "${DB_SSL:-false}")" = "true" ] || [ "${DB_SSL:-}" = "1" ]; then
    ssl_mode="require"
  fi
  if [ "$(lower "${DB_SSL_VERIFY:-false}")" = "true" ] || [ "${DB_SSL_VERIFY:-}" = "1" ]; then
    ssl_mode="verify-ca"
  fi

  ssl_args="sslmode=${ssl_mode}"
  if [ -n "${DB_SSL_CA:-}" ]; then
    if [ "$ssl_mode" = "verify-ca" ] || [ "$ssl_mode" = "verify-full" ]; then
      log "waiting for PostgreSQL CA file ${DB_SSL_CA}"
      elapsed=0
      until [ -s "${DB_SSL_CA}" ]; do
        if [ "$elapsed" -ge "$timeout" ]; then
          log "CA file is still missing or unreadable: ${DB_SSL_CA}"
          exit 1
        fi
        sleep 1
        elapsed=$((elapsed + 1))
      done
      ssl_args="${ssl_args} sslrootcert=${DB_SSL_CA}"
    fi
  fi

  if [ -z "$db_pass" ]; then
    log "DB_PASS is empty and no readable DB_PASS__FILE was provided"
    exit 1
  fi

  conn="host=${db_host} port=${db_port} user=${db_user} dbname=${db_name} ${ssl_args}"
  export PGPASSWORD="$db_pass"

  log "waiting for Poweradmin schema in PostgreSQL"
  elapsed=0
  until psql "$conn" -v ON_ERROR_STOP=1 -qAt -c "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; do
    if [ "$elapsed" -ge "$timeout" ]; then
      log "Poweradmin schema was not ready after ${timeout}s"
      psql "$conn" -v ON_ERROR_STOP=0 -c "SELECT to_regclass('public.users') AS users_table;" || true
      exit 1
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done

  log "Poweradmin schema is ready"
}

configure_poweradmin_https
wait_for_database_schema
exec "$ORIGINAL_ENTRYPOINT" "$@"
