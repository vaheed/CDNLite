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

## CI Test Failures
- Ensure PHP has `pdo_pgsql` extension in test environment.
- Ensure Postgres service is available to test job.
- Check failing test output for auth/nonce/time assumptions.
