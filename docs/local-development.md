# Local Development

[Back to docs index](index.md)

## Start And Stop

```bash
cp .env.dev.example .env
docker compose up --build
docker compose down -v
```

## Useful Commands

```bash
docker compose ps
docker compose logs -f core
docker compose logs -f edge
docker compose exec core php artisan list
docker compose exec core php artisan help
docker compose exec postgres psql -U cdnlite -d cdnlite -c 'select count(*) from domains;'
```

## Working With The Core CLI

Inside Compose, run commands from the `core` container:

```bash
docker compose exec core php artisan cdn:domain:list
```

From the host, `php core/artisan ...` works only if PHP has `pdo_pgsql` and can reach PostgreSQL at the configured `DB_HOST` and `DB_PORT`.

## Logs And Files

OpenResty logs are mounted at `edge/logs/`. Shared edge config and metric files are under `edge/config/` on the host and `/var/lib/cdnlite/` in edge containers.

## Test Loop

```bash
docker compose config
find core -name '*.php' -print0 | xargs -0 -n1 php -l
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh
pytest -q core/tests
```

Run smoke and e2e after the stack is up:

```bash
./ci/smoke.sh
./ci/e2e.sh
```
