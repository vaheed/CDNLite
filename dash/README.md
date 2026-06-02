# CDNLite Admin Dashboard

A client-only Vue 3 + TypeScript + Vite admin dashboard for operating CDNLite.

This folder is the official CDNLite admin dashboard. It lives in the repository root as `dash/` and is wired into the root `docker-compose.yml` as the `dashboard` service.

## Included

- Vue 3, TypeScript, Vite, Vue Router, Pinia, TanStack Query for Vue.
- Tailwind CSS, Headless UI-compatible component structure, ECharts charts.
- Typed API clients for sites, DNS, redirects, page rules, cache, purge, WAF, rate limit, SSL, edges, usage, security events, and edge signed developer endpoints.
- Light and dark admin themes use CSS variables for app surfaces, cards, borders, text, actions, success, warning, and danger states. The theme toggle stores `cdnlite.theme` in localStorage.
- Forms use shared field components with red required asterisks, optional markers, inline help text, non-focusable hover help icons, field-level validation errors, and page-level alerts for API failures.
- The Sites workflow opens on the sites list and reveals create/edit input details only after Add site or Edit. It supports `PATCH /api/v1/sites/{id}`, proxy enable/disable, active/disabled status changes, copy ID, delete confirmation, and selectable origin scheme/status controls.
- DNS, redirects, page rules, WAF rules, cache rules, and purge requests expose their useful API fields in explicit table columns instead of relying on raw object ordering.
- Redirect rows support edit, enable/disable with `PATCH /api/v1/sites/{id}/redirects/{redirectId}`, delete confirmation, export, import of the current rule, and test.
- DNS, page rule, WAF, and cache rule rows support edit/delete actions where the API supports them.
- Feature pages open on their records list and reveal input details only after an add/edit/import action. Page rules include a test action, SSL includes ACME issue, request, and check actions using the API `hostnames` array payload, and purge history includes detail lookup.
- Purge uses a clear required scope dropdown (`url`, `prefix`, `site`, `everything`) and only requires the URL/prefix value for URL or Prefix purges.
- Charts receive theme-aware labels, legends, axes, and tooltips. Pie charts hide outside slice labels and rely on the legend/tooltip to avoid unreadable label collisions.
- Known API enum values are rendered as selects, including DNS type, redirect status/match type, cache query mode, WAF type/action, rate-limit key type/action, and usage bucket.
- Rate Limiting exposes the backend `enabled` flag and saves the active rule with `PUT /api/v1/sites/{id}/rate-limit`.
- Troubleshooting includes a runnable diagnostics workflow for core readiness, edge readiness/heartbeats, schema readiness, security events, SSL certificate risk, and cache purge status, with a copyable report.
- Dockerfile and Nginx runtime image.
- Unit tests for env parsing, URL building, HMAC signing, formatting, diagnostics, and key forms.

Generated `dist/` output is intentionally not committed. Build artifacts are produced by `npm run build` or the dashboard Docker image build.

## Install

```bash
cp .env.example .env
npm install
```

## Development

```bash
npm run dev
```

Open <http://localhost:5173>.

## Build

```bash
npm run typecheck
npm run build
```

## Preview

```bash
npm run preview
```

## Test

```bash
npm test
```

## Docker

From the project root, build and run the whole CDNLite stack including the dashboard:

```bash
docker compose up --build
```

Open <http://localhost:8082>.

Before logging in, create an admin user:

```bash
docker compose exec core php artisan cdn:admin:create --username=admin --password='replace-with-a-long-password'
```

Build only the dashboard image:

```bash
docker compose build dashboard
```

Because this is a Vite static SPA, `VITE_*` values are build-time values. Use browser-reachable URLs such as `http://localhost:8080` for core and `http://localhost:8081` for edge.

The old dashboard-local compose templates were removed. Use the repository root `docker-compose.yml` so the dashboard, core, edge, database, and agent run as one product surface.

## Environment

All runtime configuration comes from `import.meta.env` and is validated at startup.

```bash
VITE_CDNLITE_CORE_URL=http://localhost:8080
VITE_CDNLITE_EDGE_URL=http://localhost:8081
VITE_CDNLITE_APP_NAME=CDNLite Admin
VITE_CDNLITE_API_TOKEN=
VITE_ENABLE_EDGE_DEV_TOOLS=false
VITE_ENABLE_USAGE_SIMULATOR=false
VITE_ENABLE_SSL_TOOLS=true
VITE_ENABLE_SECURITY_EVENT_VIEWER=true
VITE_ENABLE_LOG_VIEWER=true
VITE_DEFAULT_USAGE_BUCKET=minute
VITE_DASHBOARD_REFRESH_SECONDS=15
VITE_REQUEST_TIMEOUT_MS=15000
```

By default, the dashboard shows an admin login form and sends credentials to `/api/v1/admin/login`. The returned session token is kept in browser memory only. When `VITE_CDNLITE_API_TOKEN` is set, the control-plane API client can also send `Authorization: Bearer <token>` and skip the login screen for private/local deployments.

## Edge developer tools

Set this before build/dev:

```bash
VITE_ENABLE_EDGE_DEV_TOOLS=true
```

The edge token is session-memory only and is never stored in localStorage. Signed requests use:

- `Authorization: Bearer <token>`
- `X-CDNLITE-Edge-Id`
- `X-CDNLITE-Timestamp`
- `X-CDNLITE-Nonce`
- `X-CDNLITE-Signature`

Canonical string:

```text
UPPERCASE_METHOD
PATH_WITHOUT_QUERY
UNIX_TIMESTAMP
NONCE
SHA256_RAW_BODY_HEX
```

## Security notes

This is a client-only admin dashboard. It supports core-backed admin login sessions, but it does not provide production RBAC. For production, place this SPA and the CDNLite API behind real authentication at the reverse proxy or platform level. Do not expose private API or edge credentials in browser logs, error toasts, analytics, or localStorage.

## Error handling and accessibility

The dashboard API client maps known backend error codes such as `domain_already_exists`, `origin_host_required`, `invalid_json`, and `internal_server_error` to human-readable UI messages before surfacing them in views. Form pages should keep developer console logging as secondary diagnostics only; user-facing failures belong in alerts or field errors.

Tooltip help icons in shared form fields are intentionally `tabindex="-1"` so Tab navigation moves between actual inputs, selects, textareas, buttons, toggles, and submit actions. Required fields should use the shared red `*` marker rather than a separate required badge.

## API coverage map

The dashboard has typed modules for:

- `/health`, `/cdn-health`, `/ready`, and edge `/ready`
- `/api/v1/admin/login`, `/api/v1/admin/me`, `/api/v1/admin/logout`
- `/api/v1/sites`
- `/api/v1/sites/{id}/dns/records`
- `/api/v1/sites/{id}/redirects`
- `/api/v1/sites/{id}/page-rules`
- `/api/v1/sites/{id}/cache/settings`
- `/api/v1/sites/{id}/cache-rules`
- `/api/v1/sites/{id}/cache/purge`
- `/api/v1/sites/{id}/cache/purge-requests`
- `/api/v1/sites/{id}/cache/purge-requests/{requestId}`
- `/api/v1/sites/{id}/waf-rules`
- `/api/v1/sites/{id}/rate-limit`
- `/api/v1/sites/{id}/ssl/certificates`
- `/api/v1/sites/{id}/ssl/acme/issue`
- `/api/v1/sites/{id}/ssl/request`
- `/api/v1/sites/{id}/ssl/check`
- `/api/v1/sites/{id}/ssl/manual-certificate`
- `/api/v1/edge/nodes`
- `/api/v1/usage/summary`
- `/api/v1/usage/recalculate`
- `/api/v1/edge/register`, `/api/v1/edge/heartbeat`, `/api/v1/edge/config`
- `/api/v1/collector/usage`, `/api/v1/collector/security-events` when simulator tools are enabled
