#!/bin/sh

cdnlite_is_ipv4() {
  value="$1"
  echo "$value" | awk -F. '
    NF != 4 { exit 1 }
    {
      for (i = 1; i <= 4; i++) {
        if ($i !~ /^[0-9]+$/ || $i < 0 || $i > 255) exit 1
      }
    }
  '
}

cdnlite_first_valid_ip() {
  while IFS= read -r value; do
    value="$(printf '%s' "$value" | tr -d '[:space:]')"
    if cdnlite_is_ipv4 "$value"; then
      printf '%s' "$value"
      return 0
    fi
  done
  return 1
}

cdnlite_public_ip() {
  configured="${EDGE_PUBLIC_IP:-auto}"
  if [ "$configured" != "" ] && [ "$configured" != "auto" ]; then
    printf '%s' "$configured"
    return 0
  fi

  # Do not guess a public IP for private/self-hosted installs. Leaving this
  # empty keeps the node stable unless the operator explicitly configures
  # EDGE_PUBLIC_IP.
  printf ''
}

cdnlite_config_version() {
  file="$1"
  [ -s "$file" ] || return 0
  python3 - "$file" <<'PY' 2>/dev/null || true
import json
import sys
try:
    with open(sys.argv[1], "r", encoding="utf-8", errors="replace") as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)
version = data.get("version") if isinstance(data, dict) else None
if isinstance(version, int) and version >= 0:
    print(version)
PY
}
