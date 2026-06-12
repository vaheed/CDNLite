#!/usr/bin/env bash
set -euo pipefail
pdns_control rping >/dev/null 2>&1
