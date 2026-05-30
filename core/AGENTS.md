# AGENTS (Core)

## Scope
Applies to all files under `core/`.

## Runtime requirements
- Every core change must preserve API contract in `core/public_index.php`.
- Every core change must preserve CLI contract in `core/artisan`.
- Every schema change must be reflected in `core/database/schema.sql`.
- PostgreSQL must remain the default runtime backend; SQLite may be used for tests/dev override only.
- Edge control-plane and usage ingest endpoints must enforce edge auth and replay-protection headers.
- Usage aggregate behavior must preserve `minute|hour|day` bucket rebuild/query support via API and CLI.

## Documentation requirements
- If endpoint behavior changes, update root `README.md` endpoint section.
- If CLI behavior changes, update root `README.md` CLI examples.
- Every significant change must be appended to `docs/03-change-log.md`.
- If execution flow changes, update `docs/02-runtime-stages.md`.

## Delivery checks
- PHP syntax lint must pass.
- CI `smoke` and `e2e` must remain green.
