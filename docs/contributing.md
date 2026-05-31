# Contributing

[Back to docs index](index.md)

## Repository Conventions

- Treat code, tests, scripts, Compose, and CI as source of truth.
- Every code or behavior change must include matching tests or CI checks. If coverage is not practical, explain why in the handoff.
- Every user-visible behavior, endpoint, command, environment variable, script flow, or operational behavior change must update the relevant docs in the same change.
- Do not validate against live external services when a local mock exists.
- Keep API behavior in controllers/services and route wiring in `core/public_index.php`.
- Keep CLI behavior in command classes registered by `core/artisan`.
- Keep edge runtime changes in `edge/openresty/` and agent changes in `edge/agent/`.
- Update docs with every behavior change.

## Add An API Endpoint

1. Add route matching in `core/public_index.php`.
2. Put validation in the relevant controller.
3. Put persistence/business logic in a service.
4. Return JSON shapes consistent with existing endpoints.
5. Add tests or e2e coverage.
6. Update [API Reference](api-reference.md).

## Add A CLI Command

1. Create `core/app/Console/Commands/<Name>.php` with `__invoke(array $argv): int`.
2. Parse options with `CommandIO::parseOptions()`.
3. Print JSON with `CommandIO::printJson()`.
4. Register the command in `core/artisan`.
5. Add tests and update [CLI Reference](cli-reference.md).

## Add A Database Table Or Column

1. Update `core/database/schema.sql`.
2. Add migration helper logic in `Database::migrate()` if existing databases need alteration.
3. Keep IDs consistent with current text/UUID behavior.
4. Add tests for new persistence behavior.

## Add Edge Lua Behavior

1. Keep routing changes in `router.lua`, proxy changes in `proxy.lua`, metrics changes in `metrics.lua`, and config parsing in `config_loader.lua`.
2. Validate with an edge request through Compose.
3. Update [Edge Runtime](edge-runtime.md).

## Pull Request Checklist

- Relevant docs were updated, or the change has no user-visible/operational effect.
- Focused tests or CI checks cover the changed behavior.
- `docker compose config` passes.
- PHP files lint.
- Shell scripts parse with `sh -n` or `bash -n` as appropriate.
- `pytest -q core/tests` passes.
- Smoke/e2e were run or the reason they were not run is documented.
- Docs and examples match actual command names, endpoints, fields, status codes, and error strings.
