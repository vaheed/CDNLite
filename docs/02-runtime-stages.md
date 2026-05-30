# Runtime Stages

This file defines runtime stages for local development and GitHub CI.

## Stage 1: Lint and Static Validation
Purpose:
- Validate PHP syntax
- Validate shell script syntax
- Reject unfinished marker text

Commands:
```bash
find core -name '*.php' -print0 | xargs -0 -n1 php -l
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh
rg -n "WIP_MARKER" -S core edge ci
```

## Stage 2: Contract Tests
Purpose:
- Validate core behavior contracts against PostgreSQL-backed runtime assumptions.

Command:
```bash
pytest -q core/tests
```

## Stage 3: Smoke Runtime
Purpose:
- Ensure stack boots and health endpoints respond.

Commands:
```bash
docker compose up -d --build
./ci/smoke.sh
docker compose down -v
```

## Stage 4: E2E Runtime
Purpose:
- Validate traffic path and control-plane behavior.

Commands:
```bash
docker compose up -d --build
./ci/e2e.sh
docker compose down -v
```

Coverage:
- site create
- dns create/list
- proxy enable/disable behavior
- edge register/heartbeat visibility
- usage ingest visibility
- idempotent usage retries
- config sync no-change polling
- auth headers and replay rejection behavior
- edge HTML error page path

## Stage 5: Image Build
Purpose:
- Build deployable images for core, edge, edge-agent.

Commands:
```bash
docker build -t cdnlite-core -f core/Dockerfile core
docker build -t cdnlite-edge -f edge/Dockerfile edge
docker build -t cdnlite-edge-agent -f edge/agent/Dockerfile edge
```

## Stage 6: Publish Images
Purpose:
- Publish images to GHCR on push.

CI workflow:
- `.github/workflows/ci.yml`
