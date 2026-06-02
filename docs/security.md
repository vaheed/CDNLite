# Security

[Back to docs index](index.md)

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

Local quickstart can bootstrap `admin` / `admin` from `.env.dev.example` when `CDNLITE_BOOTSTRAP_ADMIN_USER=1`. The production template disables that path. Keep it disabled outside local development and create admin users with:

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

See [Edge Auth Signing](examples/edge-auth-signing.md) for copy-pasteable signing examples.
