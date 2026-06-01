# Repository Agent Notes

## Scope

Applies to the entire repository unless a narrower `AGENTS.md` overrides a section.

## Change Discipline

- Treat code, tests, docs, examples, Compose, and CI as one product surface.
- Any code or behavior change must include matching tests or CI checks. If a test is not practical, document the reason in the final handoff.
- Any user-visible behavior, command, endpoint, config, environment variable, script flow, or operational behavior change must update the relevant docs in the same change.
- Keep public API behavior stable unless the task explicitly asks for a breaking change.
- Keep shell scripts portable to their declared shell: agent scripts are POSIX `sh`; CI scripts are Bash.
- Avoid live external service mutation during validation when a local mock exists.

## Required Verification

- Lint changed PHP files with `php -l`; for broad PHP changes, lint all PHP files.
- Run `sh -n` for changed POSIX shell scripts and `bash -n` for changed Bash scripts.
- Run focused tests for the changed behavior.
- Run `pytest -q core/tests` when core behavior or contracts are touched.
- Run smoke/e2e, or state clearly why they were not run.

## CI Layout

- Keep CI on the root `docker-compose.yml`; do not add extra Compose override files for CI jobs.
- Use environment variables for job-specific behavior. Current e2e jobs set `EDGE_AGENT_IDLE=1` so `ci/e2e.sh` can provision the edge token before manually running agent scripts, and set `CDNLITE_CACHE_DEFAULT_TTL=1s` for stable cache assertions.
- The PowerDNS mock is the `powerdns` service in `docker-compose.yml` behind the `powerdns` profile. Plain e2e runs without the profile and with PowerDNS disabled; PowerDNS e2e runs `docker compose --profile powerdns up -d --build`.
- When changing CI, update `.github/workflows/ci.yml`, `docker-compose.yml`, and `docs/testing-and-ci.md` together.

## Documentation Checklist

- API behavior: update `docs/api-reference.md` and examples.
- CLI behavior: update `docs/cli-reference.md` and examples.
- Config/env behavior: update `.env.example`, `docker-compose.yml`, and `docs/configuration.md`.
- Edge agent/runtime behavior: update `docs/edge-agent.md`, `docs/edge-runtime.md`, or `docs/troubleshooting.md`.
- Workflow/process changes: update `docs/contributing.md`.
