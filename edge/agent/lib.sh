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

  for endpoint in \
    "https://api.ipify.org" \
    "https://ifconfig.me/ip" \
    "https://checkip.amazonaws.com"
  do
    detected="$(curl -fsS --max-time 3 "$endpoint" 2>/dev/null | cdnlite_first_valid_ip || true)"
    if [ "$detected" != "" ]; then
      printf '%s' "$detected"
      return 0
    fi
  done

  detected="$(hostname -i 2>/dev/null | tr ' ' '\n' | cdnlite_first_valid_ip || true)"
  if [ "$detected" != "" ]; then
    printf '%s' "$detected"
    return 0
  fi

  printf ''
}
