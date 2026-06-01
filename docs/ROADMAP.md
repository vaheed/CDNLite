# Roadmap

[Back to docs index](index.md)

## Phase 4 Status

Completed in current baseline:
- Edge runtime enforcement in OpenResty (`router.lua`, `proxy.lua`, `config_loader.lua`, `nginx.conf`) including `/ready`.
- E2E assertions for cache enforcement (`Authorization` and `Cache-Control` bypass), stale serving, signed edge auth, and edge lifecycle.
- CI release gate with `ci/release_check.sh` wired in `.github/workflows/ci.yml` before image publishing.
- Documentation sync across API, CLI, security, and troubleshooting references.

## Next Candidates

- Add focused unit tests for Lua helper functions used in edge readiness and routing.
- Expand release check to include optional PowerDNS strict profile when release tags are built.
