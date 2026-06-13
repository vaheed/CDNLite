#!/usr/bin/env bash
set -euo pipefail

MMDB_DIR="${MMDB_DIR:-/mmdb}"
MMDB_TARGET_FILE="${MMDB_TARGET_FILE:-GeoLite2-City.mmdb}"
MMDB_DOWNLOAD_INTERVAL_SECONDS="${MMDB_DOWNLOAD_INTERVAL_SECONDS:-86400}"
MMDB_DOWNLOAD_RETRIES="${MMDB_DOWNLOAD_RETRIES:-5}"
MMDB_EXPECTED_SHA256="${MMDB_EXPECTED_SHA256:-}"
MMDB_DOWNLOAD_HEADER="${MMDB_DOWNLOAD_HEADER:-}"
MMDB_DOWNLOAD_URL="${MMDB_DOWNLOAD_URL:-}"
MMDB_PROVIDER="${MMDB_PROVIDER:-dbip-jsdelivr}"

mkdir -p "$MMDB_DIR"


providers_help() {
  cat <<'HELP'
Supported providers:
  dbip-jsdelivr    Direct CDN download of DB-IP City Lite MMDB, no token.
  dbip-official    Direct official DB-IP monthly download, no token. Tries current month, then previous month.
  ip66             Direct IP66 country/continent/ASN MMDB, no token, daily upstream updates.
  generic          Use MMDB_DOWNLOAD_URL directly.
HELP
}

timestamp() {
  date -u +'%Y-%m-%dT%H:%M:%SZ'
}

log() {
  echo "[$(timestamp)] [mmdb-updater] $*"
}

case "$MMDB_DOWNLOAD_INTERVAL_SECONDS" in
  ""|*[!0-9]*)
    log "MMDB_DOWNLOAD_INTERVAL_SECONDS must be a positive integer"
    exit 1
    ;;
esac
if [ "$MMDB_DOWNLOAD_INTERVAL_SECONDS" -lt 60 ]; then
  log "MMDB_DOWNLOAD_INTERVAL_SECONDS is below 60; using 60 to avoid tight retry loops"
  MMDB_DOWNLOAD_INTERVAL_SECONDS=60
fi

case "$MMDB_DOWNLOAD_RETRIES" in
  ""|*[!0-9]*)
    log "MMDB_DOWNLOAD_RETRIES must be a non-negative integer"
    exit 1
    ;;
esac


extract_candidate() {
  local download="$1"
  local tmpdir="$2"
  local url_lower
  url_lower="$(printf '%s' "${effective_url:-$MMDB_DOWNLOAD_URL}" | tr '[:upper:]' '[:lower:]')"

  case "$url_lower" in
    *.tar.gz|*.tgz)
      tar -xzf "$download" -C "$tmpdir/extract"
      ;;
    *.zip)
      unzip -q "$download" -d "$tmpdir/extract"
      ;;
    *.mmdb.gz)
      gzip -dc "$download" > "$tmpdir/extract/${MMDB_TARGET_FILE}"
      ;;
    *)
      if file "$download" | grep -qi 'gzip compressed'; then
        gzip -dc "$download" > "$tmpdir/extract/${MMDB_TARGET_FILE}"
      else
        cp "$download" "$tmpdir/extract/${MMDB_TARGET_FILE}"
      fi
      ;;
  esac

  find "$tmpdir/extract" -type f -iname '*.mmdb' | sort | head -n 1
}

month_offset_yyyy_mm() {
  local offset="$1"
  date -u -d "$(date -u +%Y-%m-15) ${offset} month" +'%Y-%m'
}

resolve_download_urls() {
  if [ -n "$MMDB_DOWNLOAD_URL" ]; then
    printf '%s\n' "$MMDB_DOWNLOAD_URL"
    return 0
  fi

  case "$MMDB_PROVIDER" in
    dbip-jsdelivr)
      printf '%s\n' 'https://cdn.jsdelivr.net/npm/dbip-city-lite/dbip-city-lite.mmdb.gz'
      ;;
    dbip-official)
      printf 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz\n' "$(month_offset_yyyy_mm 0)"
      printf 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz\n' "$(month_offset_yyyy_mm -1)"
      ;;
    ip66)
      printf '%s\n' 'https://downloads.ip66.dev/db/ip66.mmdb'
      ;;
    generic)
      log "generic provider selected, but MMDB_DOWNLOAD_URL is empty"
      return 1
      ;;
    help|--help|-h)
      providers_help
      exit 0
      ;;
    *)
      log "unsupported MMDB_PROVIDER=$MMDB_PROVIDER"
      providers_help
      return 1
      ;;
  esac
}

download_url() {
  local url="$1"
  local output="$2"
  local curl_args

  curl_args=(
    --fail
    --location
    --show-error
    --silent
    --retry "$MMDB_DOWNLOAD_RETRIES"
    --retry-delay 5
    --retry-all-errors
    --connect-timeout 30
    --max-time 600
    --output "$output"
  )

  if [ -n "$MMDB_DOWNLOAD_HEADER" ]; then
    curl_args+=(--header "$MMDB_DOWNLOAD_HEADER")
  fi

  curl "${curl_args[@]}" "$url"
}

download_once() {
  local urls effective_url tmpdir download candidate target_tmp downloaded
  if ! urls="$(resolve_download_urls)"; then
    if [ -s "$MMDB_DIR/$MMDB_TARGET_FILE" ]; then
      log "no working provider configured; keeping existing $MMDB_TARGET_FILE"
      return 0
    fi
    log "no working provider configured and no existing MMDB file is present"
    return 1
  fi

  tmpdir="$(mktemp -d)"
  download="$tmpdir/download"
  mkdir -p "$tmpdir/extract"
  downloaded="false"

  cleanup() {
    rm -rf "$tmpdir"
  }
  trap cleanup RETURN

  while IFS= read -r effective_url; do
    [ -n "$effective_url" ] || continue
    log "downloading MMDB from $effective_url"
    if download_url "$effective_url" "$download"; then
      downloaded="true"
      break
    fi
    log "download failed for $effective_url"
  done <<< "$urls"

  if [ "$downloaded" != "true" ]; then
    return 1
  fi

  candidate="$(extract_candidate "$download" "$tmpdir")"
  if [ -z "$candidate" ] || [ ! -s "$candidate" ]; then
    log "no .mmdb file found in downloaded artifact"
    return 1
  fi

  if [ -n "$MMDB_EXPECTED_SHA256" ]; then
    printf '%s  %s\n' "$MMDB_EXPECTED_SHA256" "$candidate" | sha256sum -c -
  fi

  target_tmp="$MMDB_DIR/.${MMDB_TARGET_FILE}.tmp"
  cp "$candidate" "$target_tmp"
  chmod 0644 "$target_tmp"
  mv -f "$target_tmp" "$MMDB_DIR/$MMDB_TARGET_FILE"
  date -u +'%Y-%m-%dT%H:%M:%SZ' > "$MMDB_DIR/LAST_UPDATE"
  log "updated $MMDB_TARGET_FILE"
}

if ! download_once; then
  log "initial download failed"
  if [ ! -s "$MMDB_DIR/$MMDB_TARGET_FILE" ]; then
    exit 1
  fi
fi

while true; do
  sleep "$MMDB_DOWNLOAD_INTERVAL_SECONDS"
  download_once || log "download failed; keeping previous MMDB file"
done
