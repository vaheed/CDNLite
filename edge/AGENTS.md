# Edge Agent Notes

## Scope

Applies to all files under `edge/`.

## Runtime requirements

- Edge must continue to run from one compose deployment.
- Edge must load `/var/lib/cdnlite/config.json` and fall back to an empty host map when the file is absent or invalid.
- Agent loop must keep register, heartbeat, pull_config, push_metrics flow.
- Agent requests to core must include edge auth and replay-protection headers.
- Edge must render a clear HTML status/error page for upstream/CDN failure responses.

## Documentation requirements

- If agent timing or behavior changes, update `docs/edge-agent.md` and `README.md`.
- If edge request flow changes, update `docs/edge-runtime.md` and `docs/architecture.md`.

## Delivery checks

- Shell scripts must pass `sh -n`.
- E2E tests must validate edge proxy behavior.
