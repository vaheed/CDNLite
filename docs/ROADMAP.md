# CDNLite Phase Contract Roadmap

This file preserves the implementation contract tracked by the Phase 8-17
tests. The shorter public roadmap lives in `docs/roadmap.md`; historical
deep-dive notes live in `docs/legacy-roadmap.md`.

## Phase 8 — Simple/Advanced Protection Contract

### Progress Notes

- Added generated-rule ownership metadata for WAF and rate-limit advanced views.
- Added preview, enable, disable, undo, conflict detection, history, rollback
  point, and detach-managed coverage in smoke and e2e checks.
- Remaining Phase 8 work: dashboard Security Center cards for beginner intent
  preview/apply/disable/undo flows.

## Phase 9 — Security Center

### Progress Notes

- Added `DomainSecurityCenterTab.vue` as the simple-mode entry point for
  protection intent APIs.
- Added real backend templates for common exploits, login protection,
  WordPress hardening, checkout protection, static asset performance, API
  shield, smart rate limiting, bot shield, and emergency protection.
- API shield, smart rate limiting, bot shield, and emergency protection are
  generated through the same managed intent flow as the other Security Center
  cards.

## Phase 10 — One-Click Protection Profiles

### Progress Notes

- Added Basic Website, WordPress, API, SaaS App, E-commerce, and Emergency
  Protection profiles.
- Profiles compose real protection intents and keep generated rules traceable
  to profile ownership while preserving conflict detection and rollback.

## Phase 11 — Managed WAF Presets

### Progress Notes

- Added managed WAF metadata for generated WAF rules, edge security events,
  collector persistence, and docs.
- Added the read-only Managed WAF preset catalog with mode and group metadata.

## Phase 12 — Smart Rate Limiting

### Progress Notes

- Added the read-only Smart Rate Limiting template catalog for login protection,
  API protection, form spam, expensive pages, and emergency traffic limiting.
- Added preview impact, event enrichment, header-based keys, dry-run, challenge
  behavior, API docs, OpenAPI coverage, smoke checks, and e2e checks.

## Phase 15 — Performance Starter

### Progress Notes

- Implemented (2026-06-20): static asset caching, static query-string
  normalization, logged-in cookie bypass, dashboard controls, docs, smoke
  checks, and e2e edge coverage.

## Phase 17 — Guided Onboarding Wizard

### Progress Notes

- Added guided onboarding questions, profile recommendation, preview, apply,
  skip/resume behavior, country selection, full generated payload details,
  API docs, OpenAPI coverage, smoke checks, and e2e flow coverage.
