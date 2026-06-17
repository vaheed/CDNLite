# Production Deployment

This guide describes the checked-in deployment bundles under `deploy/`. CDNLite
uses versioned Core database migrations, but operators must still back up
PostgreSQL and PowerDNS data before changing versions and test restores before
relying on those backups.

## Choose A Topology

| Bundle | Purpose |
| --- | --- |
| `deploy/starter` | Core, dashboard, and one edge on a single small host. |
| `deploy/core` | Control plane, database, schedulers, and dashboard. |
| `deploy/edge` | One independently deployed edge POP and its agent. |
| `deploy/dnsgeo` | Supported DNSGeo stack: PostgreSQL, MMDB, Recursor, authoritative PowerDNS, and Poweradmin. |
| `deploy/powerdns-replica` | PowerDNS secondary for zone transfers. |

The root `docker-compose.yml` remains the canonical integrated DNSGeo topology
used by development, CI, smoke, e2e, and DNS stress qualification.

## Generate A Split Deployment

The repository includes an interactive generator that copies the maintained
deployment bundles and creates private `.env` files with random secrets:

```bash
deploy/generate-split-deployment.sh
```

For unattended generation, provide the required values through the environment:

```bash
REGISTRY_OWNER=example \
IMAGE_TAG=sha-0123456789abcdef \
DNS_BASE_DOMAIN=cdn.example.com \
CORE_PUBLIC_IP=192.0.2.10 \
DNSGEO_PUBLIC_IP=192.0.2.11 \
EDGE_PUBLIC_IP=198.51.100.20 \
deploy/generate-split-deployment.sh --auto --output ./generated
```

Set `EDGE_PUBLIC_IP` only if you want the edge agent to advertise a specific
public address. When it is left unset, the agent keeps the public IP blank so
manual heartbeat updates are not overwritten.

Add `--with-replica` to include `deploy/powerdns-replica`. The generator refuses
to replace an existing output directory unless `--force` is supplied. It
validates each generated Compose project when Docker Compose is available; use
`--no-compose-check` only when validation will be performed on another host.

The output contains `core/`, `dnsgeo/`, `edge/`, an optional
`powerdns-replica/`, a deployment-specific `README.md`, and a
`register-edge.sh` helper. Generated `.env` files are mode `0600` and must not
be committed or transferred through an insecure channel.

For split hosts, deploy `deploy/dnsgeo` and allow only the Core host to reach
its API bind address/port. In Dashboard Settings, configure the PowerDNS API
base as `http://DNSGEO_HOST:8089/api/v1`, server ID `localhost`, and the same
`PDNS_API_KEY`. Authoritative TCP/UDP 53 must be public; the API and Poweradmin
should remain private or be accessed through a VPN/SSH tunnel.

## Prepare A Release

1. Copy the selected bundle's `.env.example` to `.env`.
2. Replace every `CHANGE_ME` value.
3. Set `IMAGE_TAG` to an immutable published tag such as `sha-<git-sha>`.
4. Authenticate Docker to `ghcr.io` if the package is private.
5. Render and inspect the configuration:

```bash
docker compose --env-file .env config --quiet
docker compose --env-file .env config
```

Do not deploy `latest`. Core, dashboard, edge, and edge-agent must use the same
release SHA.

Dashboard `VITE_*` values are build-time settings. Runtime Compose environment
variables cannot rewrite an already-built dashboard. Build and publish the
dashboard image with the production browser URLs, or place Core and Edge at the
URLs compiled into the selected image. Never compile a privileged API token into
public dashboard assets.

## Start And Verify

```bash
docker compose pull
docker compose up -d --wait
docker compose ps
```

For a control-plane host:

```bash
curl -fsS https://api.example.com/health
curl -fsS https://api.example.com/ready
curl -fsS https://api.example.com/cdn-health
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:db:status
docker compose exec core php artisan cdn:powerdns:doctor
```

Verify an edge through `/health`, then send a Host-header request for a staged
domain. Verify apex ALIAS and CDN target answers at the same point in time.

## Security Checklist

- Terminate TLS before Core and dashboard; expose neither PostgreSQL nor the
  PowerDNS API to the public Internet.
- Disable bootstrap admin and edge-token creation after provisioning.
- Use unique long database, API, SSL, origin-shield, PowerDNS, and edge secrets.
- Restrict dashboard CORS origins and Poweradmin access.
- Allow inbound DNS TCP/UDP 53 only where authoritative DNS is deployed.
- Keep `.env` mode `0600`, rotate credentials deliberately, and retain an old
  release tag for rollback.

## Backup, Upgrade, And Rollback

Back up the Core PostgreSQL database, PowerDNS database, edge configuration
volume, and TLS material. Record the active image SHA and environment checksum.

Upgrade one environment first:

```bash
docker compose pull
docker compose exec core php artisan cdn:db:migrate --dry-run
docker compose up -d --wait
docker compose exec core php artisan cdn:db:status
```

Run readiness and DNS checks before adding traffic. To roll back, restore the
previous `IMAGE_TAG`, restore the matching database backup when a migration has
changed schema state, and run the same command. For controlled production
rollouts, set `CDNLITE_AUTO_MIGRATE=false`, run the dry-run/status commands,
take a backup, and then run `php artisan cdn:db:migrate` manually.

## Production Qualification

Smoke and live DNS e2e must pass for the release. The destructive default
`10,000 x 1,000` DNS stress run must also pass before claiming production
readiness. See [DNS Stress Testing](stress-testing.md).
