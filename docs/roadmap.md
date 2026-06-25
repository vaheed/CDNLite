---
title: Roadmap
description: Published roadmap summary for CDNLite phases and enterprise readiness.
---

# Roadmap

CDNLite is pre-1.0 and fresh-install-only. The roadmap tracks phased work toward a more complete private CDN platform while keeping production-readiness claims tied to actual smoke, e2e, stress, and release validation.

## Current Focus

- Harden the edge hot path, telemetry queues, config reload behavior, and runtime diagnostics.
- Keep DNS and PowerDNS reconciliation deterministic and observable.
- Improve analytics, Activity, security-event ingestion, and operational troubleshooting.
- Keep deployment examples, environment defaults, and validation scripts aligned with the code.

## Enterprise Direction

Planned areas include native RBAC, OIDC/SAML SSO, scoped API keys, tenant isolation, audit export, Prometheus metrics, Grafana dashboards, Helm/Kubernetes packaging, backup and restore automation, HA control-plane guidance, policy-as-code, provider integrations, WAF/rate-limit presets, and signed release artifacts.

## Source Roadmap

The detailed engineering roadmap is maintained in [docs/ROADMAP.md](https://github.com/vaheed/CDNLite/blob/main/docs/ROADMAP.md). It is intentionally excluded from the published VitePress bundle because it is large and changes frequently during phase work.
