# Security

CDNLite is suitable for local learning and controlled deployments. Production use requires deliberate authentication, secret management, TLS, and network controls.

## Authentication

| Surface | Mechanism | Recommendation |
| --- | --- | --- |
| Dashboard | `/api/v1/admin/login` returns a bearer session token. | Put dashboard behind external auth and TLS. Disable bootstrap credentials. |
| Control-plane API | Optional `CDNLITE_API_TOKEN` bearer auth. | Always set a strong token outside local dev. |
| Edge endpoints | Edge token plus timestamp, nonce, and HMAC signature. | Register per-edge tokens, rotate regularly, and protect clocks. |
| PowerDNS | API key in settings/env. | Use least-privilege API keys and the mock in tests. |

## Production Hardening

1. Set `CDNLITE_BOOTSTRAP_ADMIN_USER=0`.
2. Set `CDNLITE_BOOTSTRAP_EDGE_TOKEN=0`.
3. Replace `CDNLITE_SSL_SECRET_KEY`, `CDNLITE_ORIGIN_SHIELD_SECRET`, `EDGE_TOKEN`, and all default passwords.
4. Set `CDNLITE_API_TOKEN`.
5. Serve core and dashboard only through HTTPS.
6. Restrict PostgreSQL, PowerDNS, and internal service ports to trusted networks.
7. Keep `.env` out of commits and backups that lack encryption.
8. Rotate edge tokens after operator turnover or suspected exposure.
9. Monitor security events and audit logs.

## Sensitive Data Handling

- Never expose `EDGE_TOKEN`, `CDNLITE_API_TOKEN`, PowerDNS keys, admin passwords, or SSL private keys in dashboard screenshots, logs, tickets, or public reports.
- `VITE_*` variables are compiled into browser assets; do not place production secrets there unless the whole dashboard is private and externally protected.
- Keep `CDNLITE_SSL_SECRET_KEY` stable across restarts. Losing it can make stored certificate material unusable.
- Use secret managers for production rather than plain `.env` files where possible.

## Edge Signing

Edge signed endpoints require:

- `Authorization: Bearer <edge-token>`
- `X-CDNLITE-Edge-Id`
- `X-CDNLITE-Timestamp`
- `X-CDNLITE-Nonce`
- `X-CDNLITE-Signature`

The signature is computed over method, path, timestamp, nonce, and SHA-256 of the raw body. Nonce and timestamp checks reduce replay risk.

## Authorization Limits

The dashboard admin model is simple. It does not implement fine-grained RBAC, per-domain tenancy, SSO, or role-scoped permissions. Use external controls for production segmentation.

## Known Risks And Mitigations

| Risk | Mitigation |
| --- | --- |
| Local defaults are easy to guess. | Replace all defaults before shared use. |
| API auth can be disabled by empty `CDNLITE_API_TOKEN`. | Treat empty token as local-only; fail production readiness if missing. |
| Browser-built assets can expose Vite values. | Avoid secret `VITE_*` values. |
| Edge config contains routing and origin details. | Restrict filesystem and container access to operators. |
| Live DNS and ACME integrations mutate external services. | Use the PowerDNS mock and staging ACME directory in tests. |

## Reporting Security Issues

Open a private security report if the hosting platform supports it. Otherwise create a minimal issue without secrets or exploit details and ask maintainers for a private disclosure path.

## Edge Token Model

Edge auth is implemented by `EdgeAuthService`. Each edge ID has one bcrypt token hash in `edge_tokens`. Operators provision or replace it with:

```bash
php core/artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
php core/artisan cdn:edge:rotate-token --edge_id=edge-local-1
```

## Required Headers

| Header | Requirement |
|---|---|
| `Authorization` | `Bearer <raw token>`. |
| `X-CDNLITE-Edge-Id` | Edge ID tied to token hash. |
| `X-CDNLITE-Timestamp` | Unix timestamp within 120 seconds of core time. |
| `X-CDNLITE-Nonce` | Unique nonce. Stored for 300 seconds. |
| `X-CDNLITE-Signature` | Lowercase hex HMAC SHA-256. |

## HMAC Algorithm

The body hash is SHA-256 of the exact raw request body. For GET config, the body is empty. The canonical string is:

```text
UPPERCASE_METHOD
PATH_WITHOUT_QUERY
UNIX_TIMESTAMP
NONCE
SHA256_RAW_BODY_HEX
```

The HMAC key is the SHA-256 hex string of the raw token. The signature is `hash_hmac('sha256', canonical, hash('sha256', token))`.

## Protected Endpoints

- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/config`
- `POST /api/v1/collector/usage`
- `POST /api/v1/collector/security-events`

For register and heartbeat, header edge ID must match body `edge_id` before signature validation succeeds.

## Common Auth Failures

| Error | Status | Cause |
|---|---:|---|
| `edge_auth_required` | 401 | Missing edge ID, token, nonce, or signature. |
| `edge_auth_timestamp_out_of_range` | 401 | Timestamp differs from core time by more than 120 seconds. |
| `edge_auth_invalid_token` | 401 | No token hash or password verification failed. |
| `edge_auth_invalid_signature` | 401 | Canonical string, body, path, token, or signature is wrong. |
| `edge_auth_edge_id_mismatch` | 401 | Header edge ID differs from body edge ID. |
| `edge_auth_replay_detected` | 409 | Same edge ID and nonce already used. |

## Secret Handling

Use a long random token for real edges, store it only in the agent environment or secret manager, rotate it with `cdn:edge:rotate-token`, and keep `APP_DEBUG=0` outside development. Do not use `edge-dev-token` outside local Compose.

## Admin Dashboard

The Vue admin dashboard in `dash/` is a client-only SPA served by Nginx. The old server-rendered backend dashboard routes are removed.

Local quickstart can bootstrap `admin` / `admin` from `.env.example` when `CDNLITE_BOOTSTRAP_ADMIN_USER=1`. Keep bootstrap disabled outside local development and create admin users with:

```bash
php core/artisan cdn:admin:create --username=admin --password='replace-with-a-long-password'
```

Core stores admin passwords with PHP `password_hash`. Login returns an opaque bearer session token whose SHA-256 hash is stored in `admin_sessions`; the dashboard keeps the raw session token in browser memory only. A browser refresh requires logging in again.

If `VITE_CDNLITE_API_TOKEN` is set, the built browser bundle can still send `Authorization: Bearer <token>` for control-plane API requests. Because Vite embeds `VITE_*` values into static assets, treat that option as suitable only for local or otherwise private deployments. Edge developer tool tokens are session-memory only and must not be stored in localStorage.

In production, put both the dashboard and the CDNLite API behind real authentication at the reverse proxy or platform layer. The dashboard admin model is not production RBAC.

## Edge Cache Enforcement

The edge runtime bypasses cache storage and lookup when request risk is high:
- Non-`GET`/`HEAD` methods set cache bypass.
- `Authorization` header sets cache bypass.
- `Cache-Control: no-cache` or `no-store` sets cache bypass.

See [Examples](examples/index.md) for copy-pasteable edge and API workflows.
