# Troubleshooting

## Stack Does Not Start
- Validate compose file:
```bash
docker compose config
```
- Rebuild cleanly:
```bash
docker compose down -v
docker compose up --build
```

## Core Cannot Connect to Postgres
- Check postgres container health/logs.
- Verify `DB_*` values in compose.
- Verify port and hostname (`postgres:5432` inside network).

## Core Logs Are Empty
- Ensure core log switches are enabled in `.env`:
  - `APP_LOG_ENABLED=1`
  - `APP_LOG_LEVEL=debug` (for maximum detail)
  - `APP_DEBUG=1` (include error detail in API responses)
- Recreate core after env changes:
```bash
docker compose up -d --build core
```
- Follow core logs:
```bash
docker compose logs -f core
```
- Follow all services:
```bash
docker compose logs -f
```

## Edge Returns Error Page
- Verify host exists in core site list.
- Verify site proxy is enabled.
- Verify edge config has host entry.
- Verify origin upstream is reachable.

## Edge Registration/Heartbeat Fails
- Ensure edge token was registered in core.
- Verify edge header/signature generation.
- Check timestamp skew between edge and core.

## Usage Not Updating
- Verify edge metrics file and push loop.
- Check collector endpoint auth and status.
- Run `cdn:usage:summary` directly.

## PowerDNS Sync Fails (`powerdns_api_error`)
- Verify:
  - `POWERDNS_ENABLED=1`
  - `POWERDNS_API_URL`
  - `POWERDNS_API_KEY`
  - `POWERDNS_SERVER_ID`
- For strict failure mode, set `POWERDNS_STRICT=1`.
- Check core logs for structured error details (`status`, upstream `response`, record/domain context):
```bash
docker compose logs -f core
```
- Zone existence is automatic on site create when `POWERDNS_ENABLED=1`.
- For geolocation/LUA rules, use `type=LUA` with content like `A ";if country(...) then ... end"`.

## Proxied A Record Uses Wrong IP
- For `proxied=true` and `type=A`, CDNLite publishes online edge `public_ip` values (not the request `content` value).
- Ensure edge node public IPs are correct:
```bash
curl -s http://localhost:8080/api/v1/edge/nodes
```
- Trigger automatic refresh by edge register/heartbeat update and re-check zone in PowerDNS.

## CI Test Failures
- Ensure PHP has `pdo_pgsql` extension in test environment.
- Ensure Postgres service is available to test job.
- Check failing test output for auth/nonce/time assumptions.
