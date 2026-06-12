#!/usr/bin/env bash
set -euo pipefail
mmdb_dir="${MMDB_DIR:-/mmdb}"
mmdb_file="${MMDB_TARGET_FILE:-GeoLite2-City.mmdb}"
test -s "${mmdb_dir}/${mmdb_file}"
