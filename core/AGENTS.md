# Core Agent Notes

## Scope

Applies to all files under `core/`.

## Laravel Runtime Requirements

- Laravel is the authoritative core runtime. API work belongs in
  `core/routes/api.php`, Laravel controllers, Laravel services, requests,
  resources, middleware, migrations, seeders, and feature tests.
- Laravel Artisan commands are the forward CLI surface. Legacy command runners
  may be used only as migration reference until their workflows are ported.
- `core/public_index.php`, old module controllers/services, and old support
  classes are not compatibility targets. Do not add or restore behavior there
  except to remove legacy aliases, prevent accidental fallback, or keep a
  temporarily unmigrated workflow honest while it is still explicitly outside
  the current slice.
- Do not preserve old API/CLI contracts by adding aliases, shims, deprecated
  fields, or compatibility routing. When behavior is migrated, update clients,
  docs, OpenAPI, tests, Compose, and CI to the new Laravel contract.
- Old code may be read as product/spec reference, but the implementation must be
  Laravel-native. Never route new behavior back to `core/public_index.php` or
  old service layers to satisfy legacy tests.
- Every schema change must be reflected in `core/database/schema.sql`.
- PostgreSQL is the only supported backend for runtime and tests.
- Edge control-plane and usage ingest endpoints must enforce edge auth and replay-protection headers.
- Usage aggregate behavior must preserve `minute|hour|day` bucket rebuild/query support via API and CLI.

## Documentation requirements

- If endpoint behavior changes, update root `README.md` usage/documentation section.
- If CLI behavior changes, update root `README.md` CLI examples.
- If endpoint behavior changes, update `docs/api/api.md`.
- If CLI behavior changes, update `docs/setup.md` and `docs/examples/index.md`.
- If execution flow changes, update `docs/architecture.md` or the relevant runtime guide.

## Delivery checks

- PHP syntax lint must pass.
- Focused core contract tests must be added or updated for behavior that edge agents, CLI, or API clients depend on.
- Laravel feature tests must use PostgreSQL, not SQLite.
- Run the Laravel Docker test path for migrated core behavior through the normal
  runtime service: `docker compose up -d --build core` and
  `docker compose exec -T core php artisan test`.
- `pytest -q core/tests` should pass once obsolete legacy contracts for migrated
  workflows are converted, retired, or isolated. Do not make it pass by adding
  backward-compatibility shims.
- CI `smoke` and `e2e` must remain green.
