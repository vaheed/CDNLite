# IDE Report

Date: 2026-06-15

## 1. Completed Items

- Phase 3: added targeted e2e coverage for HTTPS origins with configured SNI.
- Phase 3: added targeted e2e coverage for origin Host header behavior when `preserve_host=false`.
- Phase 3: added targeted e2e coverage for CDN Host header preservation when `preserve_host=true`.
- Phase 6: added targeted e2e coverage for a real proxied edge request appearing in Activity after agent metrics ingest.
- Phase 6: added targeted e2e coverage for a 502 edge request showing selected origin and router/upstream diagnostics in Activity.
- Fixed user-reported e2e failure where SNI was not observable in the origin fixture by adding a dedicated `phase3-sni.local` TLS virtual host.
- Fixed user-reported e2e failure where later POST proxy validation expected HTTPS after an HTTP-origin test by making restore payloads explicitly set `origin_scheme: "https"`.
- Fixed user-reported e2e failure where DNS-linked origin updates corrupted A/AAAA record content and triggered strict PowerDNS `invalid_dns_record_content`.
- Fixed user-reported e2e failure where TLS verify-mode expected HTTPS-to-HTTP fallback; explicit HTTPS self-signed origins now assert a 502 with request id.
- Fixed user-reported e2e failure where TLS verify-mode still returned 200 by enforcing `proxy_ssl_verify on` for default proxy paths and routing `tls_verify=ignore` origins through no-verify locations.
- Fixed user-reported e2e failure where Activity request lookup returned `request_not_found` after no-verify routing by preserving domain/origin metadata in nginx variables across internal redirects.
- Fixed e2e harness false failure where raw metrics spool files were required to contain request ids even though the edge-agent can drain them before the assertion; the test now retries the durable Activity lookup.
- Updated `docs/ROADMAP.md` with checkboxes, notes, changed files, lightweight validation, and remaining manual validation blockers.

## 2. Changed Files

- `ci/e2e.sh`
- `ci/origin-mock/nginx.conf`
- `core/app/Modules/Proxy/Services/OriginHealthService.php`
- `edge/openresty/nginx.conf`
- `edge/openresty/lua/metrics.lua`
- `edge/openresty/lua/proxy.lua`
- `core/tests/test_edge_phase3_contract.py`
- `core/tests/test_origin_record_refactor_contract.py`
- `core/tests/test_phase6_activity_diagnostics_contract.py`
- `docs/ROADMAP.md`
- `docs/ide-report.md`

## 3. Database/Migration Impact

- No database schema changes.
- No migration changes.
- DNS-linked origin updates no longer rewrite public DNS record `content` or `origin_content`; existing corrupted local rows, if any, should be corrected by updating the DNS record content back to the intended IP/target before rerunning strict reconciliation.
- Edge upstream TLS behavior changed: `tls_verify=verify` now enforces CA validation; `tls_verify=ignore` is routed through explicit no-verify proxy locations.
- Edge metrics now fall back to nginx variables for domain and origin metadata so internal redirects do not drop Activity correlation fields.
- No data reset, wipe, truncate, or fresh-install-only logic was used.

## 4. Tests Run

- `bash -n ci/e2e.sh`
- `pytest -q core/tests/test_edge_phase3_contract.py`
- `pytest -q core/tests/test_phase6_activity_diagnostics_contract.py`
- `pytest -q core/tests/test_edge_phase3_contract.py core/tests/test_phase6_activity_diagnostics_contract.py`
- `php -l core/app/Modules/Proxy/Services/OriginHealthService.php`
- `pytest -q core/tests/test_origin_record_refactor_contract.py core/tests/test_edge_phase3_contract.py core/tests/test_phase6_activity_diagnostics_contract.py core/tests/test_phase7_config_invalidation_contract.py`
- `pytest -q core/tests/test_edge_phase3_contract.py core/tests/test_origin_record_refactor_contract.py core/tests/test_phase6_activity_diagnostics_contract.py`
- `pytest -q core/tests/test_edge_phase3_contract.py core/tests/test_phase6_activity_diagnostics_contract.py core/tests/test_origin_record_refactor_contract.py`
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
- User reported `191 passed in 46.32s` and smoke passing at `2026-06-15T19:05:35Z`; the subsequent e2e failures were addressed but need a rerun.
- User reported a later e2e failure at `HTTP fallback origin update failed` with `dns_publish_failed` / `invalid_dns_record_content`; the service bug was addressed but needs a rerun.
- User reported `193 passed in 45.60s` and smoke passing at `2026-06-15T19:19:58Z`; the later e2e verify-mode fallback expectation was corrected but needs a rerun.
- User reported the verify-mode self-signed request still returned 200; upstream certificate verification enforcement was added but needs a rerun.
- User reported Phase 6 Activity lookup returned `request_not_found`; no-verify internal redirect metadata preservation was added but needs a rerun.
- User reported raw metrics file missing the Activity request id; the transient-spool assertion was replaced with durable Activity lookup retries but needs a rerun.
- The fixed Phase 3 e2e assertions still need runtime validation on a disposable stack.
- The new Phase 6 Activity ingest and 502 diagnostics assertions still need runtime validation on a disposable stack.
- The SNI assertion uses the local self-signed origin fixture with `tls_verify=ignore`; this proves edge SNI forwarding, not public CA validation.
