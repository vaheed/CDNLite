# IDE Report

Date: 2026-06-15

## 1. Completed Items

- Phase 3: added targeted e2e coverage for HTTPS origins with configured SNI.
- Phase 3: added targeted e2e coverage for origin Host header behavior when `preserve_host=false`.
- Phase 3: added targeted e2e coverage for CDN Host header preservation when `preserve_host=true`.
- Phase 6: added targeted e2e coverage for a real proxied edge request appearing in Activity after agent metrics ingest.
- Phase 6: added targeted e2e coverage for a 502 edge request showing selected origin and router/upstream diagnostics in Activity.
- Updated `docs/ROADMAP.md` with checkboxes, notes, changed files, lightweight validation, and remaining manual validation blockers.

## 2. Changed Files

- `ci/e2e.sh`
- `ci/origin-mock/nginx.conf`
- `core/tests/test_edge_phase3_contract.py`
- `core/tests/test_phase6_activity_diagnostics_contract.py`
- `docs/ROADMAP.md`
- `docs/ide-report.md`

## 3. Database/Migration Impact

- No database schema changes.
- No migration changes.
- No data reset, wipe, truncate, or fresh-install-only logic was used.

## 4. Tests Run

- `bash -n ci/e2e.sh`
- `pytest -q core/tests/test_edge_phase3_contract.py`
- `pytest -q core/tests/test_phase6_activity_diagnostics_contract.py`
- `git diff --check`

## 5. Smoke/E2E Commands For Manual Run

Run these against a disposable rebuilt stack:

```bash
docker compose up -d --build
./ci/e2e.sh
```

Optional focused edge-log validation after preparing routed hosts:

```bash
EDGE_LOG_SMOKE_VALID_HOST=<known-good-routed-host> \
EDGE_LOG_SMOKE_DOWN_HOST=<host-routed-to-down-origin> \
./ci/edge_log_smoke.sh
```

## 6. Unresolved Risks Or Blockers

- Codex did not run Docker, smoke, or e2e tests per instruction.
- The new Phase 3 e2e assertions still need runtime validation on a disposable stack.
- The new Phase 6 Activity ingest and 502 diagnostics assertions still need runtime validation on a disposable stack.
- The SNI assertion uses the local self-signed origin fixture with `tls_verify=ignore`; this proves edge SNI forwarding, not public CA validation.
