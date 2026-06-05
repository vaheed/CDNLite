# Troubleshooting

[Back to docs index](index.md)

| Problem | Symptom | Likely cause | Diagnostic command | Fix |
|---|---|---|---|---|
| Docker build failure | Image build exits non-zero | Network, base image, Dockerfile package install | `docker compose build --no-cache` | Retry with network; inspect failing Dockerfile layer. |
| Database connection failure | Core 500 or CLI PDO error | PostgreSQL not ready or DB env mismatch | `docker compose exec postgres pg_isready -U cdnlite -d cdnlite` | Start DB; align `DB_*` and `POSTGRES_*`. |
| Core health failure | `curl /health` fails | Core container down or port changed | `docker compose ps core && docker compose logs core` | Start core; check `CORE_HOST_PORT`. |
| Edge health failure | `curl :8081/health` fails | Edge container down or port changed | `docker compose logs edge` | Start edge; check `EDGE_HOST_PORT`. |
| Edge readiness failure | `curl :8081/ready` returns 503 | Missing or invalid edge config JSON | `curl -i http://localhost:8081/ready && docker compose exec edge-agent sh -lc 'cat "$EDGE_CONFIG_PATH"'` | Run `/agent/pull_config.sh`; fix invalid JSON payload generation. |
| Edge returns 502 | Custom error page | Unknown host, disabled proxy, missing config, or origin failure | `docker compose exec edge-agent sh -lc 'cat "$EDGE_CONFIG_PATH"'` | Pull config, enable proxy, fix origin. |
| Domain not found | API returns `domain_not_found` | Wrong UUID or deleted domain | `curl -s http://localhost:8080/api/v1/domains` | Use returned domain ID. |
| DNS sync failure | 502 or log `powerdns_*_failed` | PowerDNS disabled/misconfigured/API key bad | `docker compose logs core | grep powerdns` | Fix PowerDNS env or set `POWERDNS_STRICT=0`. |
| Edge auth 401 | `edge_auth_*` error | Missing token, invalid signature, stale timestamp | `docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token` | Register correct token; rebuild signature. |
| Edge public IP wrong | `cdn:edge:list` shows empty, private, or old `public_ip` | Public detection blocked or override is stale | `docker compose exec edge-agent sh -lc '. /agent/lib.sh; cdnlite_public_ip; echo'` | Set `EDGE_PUBLIC_IP=auto` or a concrete IPv4; run `/agent/register.sh` or `/agent/heartbeat.sh`. |
| Replay 409 | `edge_auth_replay_detected` | Reused nonce | Inspect signing script nonce generation | Use a new nonce per request. |
| Validation 422 | Required field error | Missing body field or invalid query | Check response JSON | Add required fields; use valid bucket. |
| Empty usage summary | Counts are zero | No raw usage or aggregates not rebuilt | `php core/artisan cdn:usage:summary` | Push/ingest usage; run recalculate for bucket summaries. |
| Config not updating | Edge routes old host state | Agent has not pulled, snapshot unchanged, or downloaded config failed validation | `docker compose exec edge-agent sh -lc '/agent/pull_config.sh && cat "$EDGE_CONFIG_PATH"'` | Force pull; confirm domain `proxy_enabled=true`. The agent preserves the last-known-good config on HTTP or JSON validation failure. |
| Cache result unexpected | `X-CDNLITE-Cache` is `MISS`, `BYPASS`, or empty | First request for a key, `Authorization`, `Cache-Control: no-cache/no-store`, non-GET/HEAD method, expired item, or no stored stale response | `curl -i -H 'Host: example.test' http://localhost:8081/path` | Repeat the same GET/HEAD without bypass headers; verify `CDNLITE_CACHE_DEFAULT_TTL` and origin status. |
| Metrics not clearing | `METRIC_PATH` remains non-empty after push | Collector returned 4xx/5xx or network failed | `docker compose logs edge-agent` | Fix collector/API/auth issue. The agent keeps metrics and a `.payload` spool until ingest succeeds. |
