# Future AI-Agent Development Plan

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

## Future automation targets
- Auto-generate CRUD command skeletons per module.
- Auto-check API route naming conventions.
- Auto-check service/controller separation.
- Auto-generate usage report snapshots for billing integration.
- Auto-validate config snapshot backward compatibility.
