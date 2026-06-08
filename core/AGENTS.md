# Core Agent Notes

## Scope

Applies to all files under `core/`.

## Runtime requirements

- Every core change must preserve API contract in `core/public_index.php`.
- Every core change must preserve CLI contract in `core/artisan`.
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
- `pytest -q core/tests` must pass for core changes.
- CI `smoke` and `e2e` must remain green.
