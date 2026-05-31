# Future Development Plan

## Objective
Make this project agent-friendly so multiple AI agents can safely contribute without architecture drift.

## Planned agent roles
- Architect Agent: guards module boundaries and interfaces.
- API Agent: builds routes/controllers/requests.
- Service Agent: implements business logic and integration adapters.
- Edge Agent: implements Lua/OpenResty and edge-agent behavior.
- Data Agent: owns migrations, rollups, and query performance.
- QA Agent: writes tests, failure cases, and regression checks.
- Ops Agent: owns Docker Compose, runtime config, and deployment notes.

## Execution protocol
1. Task starts with scope file (`tasks/<id>.md`) including constraints.
2. Agent proposes minimal diff plan.
3. Agent implements module-local changes only.
4. Agent runs tests/lint relevant to touched modules.
5. Agent updates docs and marks acceptance checklist.

## Guardrails for future work
- No cross-module coupling without interface contract.
- No feature merge without API + CLI parity for core operations.
- No edge behavior change without fallback/last-known-good validation.
- No schema change without migration safety and rollback note.
- No code/config change without corresponding tests and CI validation updates.

## Mandatory Testing Rule For Every Change

Every future change must include all necessary tests and verification updates before merge.

Required by default:
- Update or add automated tests that cover the changed behavior.
- Update `ci/smoke.sh` and/or `ci/e2e.sh` when runtime behavior, API, CLI, edge, DNS, usage, auth, or DB flow changes.
- Add negative/failure-path checks for new logic when applicable.
- Add database assertions for persistence-impacting changes.
- Update PowerDNS strict-mode coverage when DNS sync behavior changes.
- Keep tests deterministic and idempotent (no fixed IDs, no brittle timing assumptions).
- Ensure CI workflow jobs and artifacts remain green and meaningful.

Pull requests should not be considered complete unless:
- The change is covered by relevant tests.
- Existing tests are adjusted for intended behavior changes.
- CI smoke/E2E coverage is updated when needed.

## Future automation targets
- Auto-generate CRUD command skeletons per module.
- Auto-check API route naming conventions.
- Auto-check service/controller separation.
- Auto-generate usage report snapshots for billing integration.
- Auto-validate config snapshot backward compatibility.

## CI Smoke And E2E

### Local smoke
```bash
cp .env.example .env
docker compose up -d --build
./ci/smoke.sh
```

### Local e2e
```bash
cp .env.example .env
docker compose up -d --build
./ci/e2e.sh
```

### Local e2e with PowerDNS strict mode
```bash
cp .env.example .env
POWERDNS_ENABLED=1 POWERDNS_STRICT=1 POWERDNS_API_KEY=test-key \
docker compose -f docker-compose.yml -f ci/docker-compose.ci.yml up -d --build

POWERDNS_ENABLED=1 POWERDNS_STRICT=1 POWERDNS_API_URL=http://localhost:8089 \
POWERDNS_API_KEY=test-key ./ci/e2e.sh
```

Notes:
- CI currently uses `ci/pdns_mock_server.py` as a deterministic PowerDNS-compatible mock endpoint for strict-mode verification.
- Reports are generated to `ci/reports/` as Markdown + JSON + JUnit XML.
