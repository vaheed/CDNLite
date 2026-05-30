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
Expected:
- All checks pass with zero syntax errors and zero marker matches.

## Stage 2: Unit Test
Purpose:
- Validate core contract-level assumptions quickly.

Command:
```bash
pytest -q core/tests
```
Expected:
- Test job exits with status `0`.

## Stage 3: Smoke Runtime
Purpose:
- Ensure stack boots and health endpoints respond.

Commands:
```bash
docker compose up -d --build
./ci/smoke.sh
docker compose down -v
```
Expected:
- Core and edge health checks return success.

## Stage 4: E2E Runtime
Purpose:
- Validate real traffic and control-plane behavior.

Commands:
```bash
docker compose up -d --build
./ci/e2e.sh
docker compose down -v
```
Covers:
- site create
- dns create/list
- proxy enable/disable behavior at edge
- edge registration/heartbeat visibility
- usage ingestion visibility
- idempotent usage ingest retries (`idempotency_key`)
- config sync no-change polling (`if_version`)
- edge auth headers and replay rejection (`X-CDNLITE-Timestamp`, `X-CDNLITE-Nonce`)

## Stage 5: Build Images
Purpose:
- Build deployable images for core, edge, edge-agent.

Commands:
```bash
docker build -t cdnlite-core -f core/Dockerfile core
docker build -t cdnlite-edge -f edge/Dockerfile edge
docker build -t cdnlite-edge-agent -f edge/agent/Dockerfile edge
```
Expected:
- All images build successfully.

## Stage 6: Push Images (GitHub)
Purpose:
- Publish images to GHCR on push.

CI job:
- `.github/workflows/ci.yml` -> `build_and_push`

Tags:
- `ghcr.io/<owner>/cdnlite-core:sha-<sha>`
- `ghcr.io/<owner>/cdnlite-core:latest`
- `ghcr.io/<owner>/cdnlite-edge:sha-<sha>`
- `ghcr.io/<owner>/cdnlite-edge:latest`
- `ghcr.io/<owner>/cdnlite-edge-agent:sha-<sha>`
- `ghcr.io/<owner>/cdnlite-edge-agent:latest`
