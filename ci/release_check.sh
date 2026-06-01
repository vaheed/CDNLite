#!/usr/bin/env bash
set -Eeuo pipefail

./ci/smoke.sh
./ci/e2e.sh
