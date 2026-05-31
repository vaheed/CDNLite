# Troubleshooting

[Back to docs index](index.md)

| Problem | Symptom | Likely cause | Diagnostic command | Fix |
|---|---|---|---|---|
| Docker build failure | Image build exits non-zero | Network, base image, Dockerfile package install | `docker compose build --no-cache` | Retry with network; inspect failing Dockerfile layer. |
| Database connection failure | Core 500 or CLI PDO error | PostgreSQL not ready or DB env mismatch | `docker compose exec postgres pg_isready -U cdnlite -d cdnlite` | Start DB; align `DB_*` and `POSTGRES_*`. |
| Core health failure | `curl /health` fails | Core container down or port changed | `docker compose ps core && docker compose logs core` | Start core; check `CORE_HOST_PORT`. |
| Edge health failure | `curl :8081/health` fails | Edge container down or port changed | `docker compose logs edge` | Start edge; check `EDGE_HOST_PORT`. |
| Edge returns 502 | Custom error page | Unknown host, disabled proxy, missing config, or origin failure | `docker compose exec edge-agent sh -lc 'cat "$EDGE_CONFIG_PATH"'` | Pull config, enable proxy, fix origin. |
| Site not found | API returns `site_not_found` | Wrong UUID or deleted site | `curl -s http://localhost:8080/api/v1/sites` | Use returned site ID. |
| DNS sync failure | 502 or log `powerdns_*_failed` | PowerDNS disabled/misconfigured/API key bad | `docker compose logs core | grep powerdns` | Fix PowerDNS env or set `POWERDNS_STRICT=0`. |
| Edge auth 401 | `edge_auth_*` error | Missing token, invalid signature, stale timestamp | `docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token` | Register correct token; rebuild signature. |
| Replay 409 | `edge_auth_replay_detected` | Reused nonce | Inspect signing script nonce generation | Use a new nonce per request. |
| Validation 422 | Required field error | Missing body field or invalid query | Check response JSON | Add required fields; use valid bucket. |
| Empty usage summary | Counts are zero | No raw usage or aggregates not rebuilt | `php core/artisan cdn:usage:summary` | Push/ingest usage; run recalculate for bucket summaries. |
| Config not updating | Edge routes old host state | Agent has not pulled or snapshot unchanged | `docker compose exec edge-agent sh -lc '/agent/pull_config.sh && cat "$EDGE_CONFIG_PATH"'` | Force pull; confirm site `proxy_enabled=true`. |
