# Edge Agent

[Back to docs index](index.md)

The edge agent is an Alpine container built from `edge/agent/Dockerfile`. It installs `curl`, `openssl`, and `coreutils`, then runs `/agent/run.sh`.

## Scripts

| Script | Purpose |
|---|---|
| `run.sh` | Creates config/metric files, runs initial register and config pull, then loops every 10 seconds. |
| `lib.sh` | Shared agent helpers, including public IPv4 discovery. |
| `register.sh` | Detects public IPv4 and sends signed `POST /api/v1/edge/register`. |
| `heartbeat.sh` | Detects public IPv4 and sends signed `POST /api/v1/edge/heartbeat`. |
| `pull_config.sh` | Signed `GET /api/v1/edge/config`; atomically writes `EDGE_CONFIG_PATH`. |
| `push_metrics.sh` | Converts metrics NDJSON into `{"items":[...]}` and signed-posts usage. |

## Required Environment

`CORE_URL`, `EDGE_ID`, `EDGE_TOKEN`, `EDGE_CONFIG_PATH`, and `METRIC_PATH` are required for normal operation. Registration and heartbeat also use `EDGE_HOSTNAME`, `EDGE_PUBLIC_IP`, `EDGE_REGION`, and `EDGE_VERSION`.

`EDGE_PUBLIC_IP=auto` is the default. In that mode the agent asks public IPv4 endpoints for its address and falls back to the first local IPv4 address if public detection is unavailable. Set `EDGE_PUBLIC_IP` to a concrete IPv4 address only when you want to override detection.

## Register Flow

1. Operator provisions token: `php artisan cdn:edge:register-token --edge_id=<id> --token=<token>`.
2. Agent detects its public IPv4 address.
3. Agent builds JSON body with edge identity, public IP, region, and version.
4. Agent signs `POST /api/v1/edge/register`.
5. Core verifies token, timestamp, nonce, signature, and edge ID match.
6. Core upserts `edge_nodes` with status `online`.

## Heartbeat Flow

`heartbeat.sh` sends `edge_id`, `hostname`, detected `public_ip`, `region`, and `version`. Core updates `last_heartbeat`, `last_heartbeat_at`, `status=online`, `updated_at`, and any non-empty edge metadata fields. If the public IP changes, the new value is saved automatically and the platform edge DNS zone is recomputed. Customer zones are not rewritten. If no edge node row exists after auth succeeds, core returns `edge_not_found`.

## Config Pull Flow

`pull_config.sh` signs a GET request with an empty body hash and writes the response to a new file before moving it to `EDGE_CONFIG_PATH`. The script does not pass `if_version`; it always writes the full response it receives.

## Metrics Push Flow

`push_metrics.sh` exits quietly if the metric file is missing or empty. Otherwise it wraps non-empty NDJSON lines as `items`, signs the body, posts to `/api/v1/collector/usage`, truncates the metric file on success, and removes the payload file.

## Local Examples

```bash
docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
docker compose exec edge-agent sh -lc '/agent/register.sh'
docker compose exec edge-agent sh -lc '/agent/heartbeat.sh'
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
docker compose exec edge-agent sh -lc '/agent/push_metrics.sh'
```

## Troubleshooting

- Registration 401: token row missing, wrong token, stale timestamp, bad nonce, or invalid signature.
- Registration 409: nonce reused.
- PowerDNS still points to an old edge IP: check `cdn:edge:list`, confirm `public_ip` changed, then run `php artisan cdn:dns:sync-edge-domain` or `/agent/heartbeat.sh` to force an edge-zone sync.
- Config not updating: inspect `edge/config/config.json`, run `/agent/pull_config.sh`, and check core logs.
- Metrics not arriving: check `edge/config/metrics.ndjson`, then run `/agent/push_metrics.sh` and inspect core logs.
