# CLI Workflow

[Back to docs index](../index.md)

Run examples inside the `core` container or from a host with PHP/PostgreSQL access.

```bash
docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
```

```json
{"ok":true,"edge_id":"edge-local-1"}
```

## Domain

```bash
php core/artisan cdn:domain:create --name=CLI-Demo --domain=cli-demo.local
php core/artisan cdn:domain:list
php core/artisan cdn:domain:update --id=11111111-1111-4111-8111-111111111111 --name=CLI-Demo-Updated
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"CLI-Demo-Updated","domain":"cli-demo.local"}}
```

Disable and re-enable proxy through update:

```bash
php core/artisan cdn:domain:update --id=11111111-1111-4111-8111-111111111111 --proxy_enabled=0
php core/artisan cdn:domain:update --id=11111111-1111-4111-8111-111111111111 --proxy_enabled=1
```

## DNS

```bash
php core/artisan cdn:dns:add-record --domain_id=11111111-1111-4111-8111-111111111111 --type=A --name=@ --content=127.0.0.1 --ttl=300 --proxied=1
php core/artisan cdn:dns:update-record --domain_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222 --content=127.0.0.2 --ttl=120
php core/artisan cdn:dns:list-records --domain_id=11111111-1111-4111-8111-111111111111
```

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","origin_type":"A","origin_content":"127.0.0.2","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":120,"proxied":true}]}
```

## Config And Edge Check

```bash
php core/artisan cdn:edge:sync-config
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
curl -s -H 'Host: cli-demo.local' http://localhost:8081/health
```

```json
{"ok":true}
```

## Usage

```bash
php core/artisan cdn:usage:ingest --domain_id=11111111-1111-4111-8111-111111111111 --edge_node_id=edge-local-1 --requests_count=10 --bytes_in=1000 --bytes_out=5000 --status=200 --idempotency_key=cli-1
php core/artisan cdn:usage:recalculate --domain_id=11111111-1111-4111-8111-111111111111
php core/artisan cdn:usage:summary --domain_id=11111111-1111-4111-8111-111111111111 --bucket=minute
```

```json
{"data":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

## Cleanup

```bash
php core/artisan cdn:dns:delete-record --domain_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222
php core/artisan cdn:domain:delete --id=11111111-1111-4111-8111-111111111111
```

```json
{"ok":true}
```
