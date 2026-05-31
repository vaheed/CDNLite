# CDNLite Documentation

[Back to README](../README.md)

This documentation is based on the current repository code, scripts, Docker files, tests, and CI configuration.

## Guides

| Page | Purpose |
|---|---|
| [Quick Start](quick-start.md) | Run the local stack and make first API, DNS, and edge requests. |
| [Project Overview](project-overview.md) | Understand what CDNLite solves and how the parts fit. |
| [Architecture](architecture.md) | Mermaid diagrams, module map, data flow, and failure paths. |
| [Local Development](local-development.md) | Developer setup and day-to-day commands. |
| [Configuration](configuration.md) | Environment variables, ports, and volumes. |
| [API Reference](api-reference.md) | Implemented HTTP endpoints. |
| [CLI Reference](cli-reference.md) | Registered `php core/artisan` commands. |
| [Edge Runtime](edge-runtime.md) | OpenResty behavior and Lua modules. |
| [Edge Agent](edge-agent.md) | Agent scripts and signed sync flows. |
| [DNS And PowerDNS](dns-and-powerdns.md) | DNS model and optional PowerDNS sync. |
| [Usage And Metrics](usage-and-metrics.md) | Ingest payloads, summaries, and aggregates. |
| [Security](security.md) | Edge token and HMAC model. |
| [Operations Runbook](operations-runbook.md) | Common operator procedures. |
| [Troubleshooting](troubleshooting.md) | Symptoms, diagnostics, and fixes. |
| [Testing And CI](testing-and-ci.md) | Tests, smoke, e2e, and GitHub Actions. |
| [Contributing](contributing.md) | Repository conventions and checklists. |
| [Glossary](glossary.md) | Terms used by the project. |

## Examples

- [API Workflow](examples/api-workflow.md)
- [CLI Workflow](examples/cli-workflow.md)
- [Edge Auth Signing](examples/edge-auth-signing.md)
- [Sample Config Snapshot](examples/sample-config-snapshot.md)
- [Sample Usage Payloads](examples/sample-usage-payloads.md)

## Source-Of-Truth Files

Routes are in `core/public_index.php`; CLI commands are registered in `core/artisan`; schema is in `core/database/schema.sql`; the edge runtime is in `edge/openresty/`; the agent is in `edge/agent/`; Compose and CI behavior is in `docker-compose.yml`, `ci/`, and `.github/workflows/ci.yml`.
