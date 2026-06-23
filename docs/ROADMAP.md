# CDNLite Product and Engineering Roadmap

> Self-hosted private CDN control plane and edge platform  
> Last updated: 2026-06-23  
> Roadmap status: Proposed replacement  
> Canonical file: `ROADMAP.md`

CDNLite is being developed as a self-hosted CDN platform for companies, hosting providers, internal infrastructure teams, private edge networks, labs, and controlled production deployments.

This roadmap prioritizes finishing and proving the existing product before expanding into larger enterprise and ecosystem features. A control in the dashboard, a database field, an API option, or a configuration snapshot field is not considered a finished feature unless the edge or control plane performs the promised behavior and that behavior is covered by automated runtime tests.

This document is directional. It does not promise release dates. Security, correctness, availability, and data-loss issues may change the order of work.

---

## 1. Product direction

CDNLite should become a dependable private CDN platform with:

- A secure and observable control plane.
- Fast, bounded, and predictable edge request handling.
- Correct HTTP caching and safe cache invalidation.
- Reliable origin routing, health checking, load balancing, failover, and overload protection.
- DNS and GeoDNS reconciliation with clear operational state.
- Automated TLS certificate lifecycle management.
- Practical WAF, bot, rate-limit, API, and abuse protection.
- Useful real-time and historical analytics without unbounded queries.
- A database architecture that separates transactional control-plane work from high-volume telemetry and reporting work.
- Repeatable deployment, backup, restore, upgrade, and rollback workflows.
- Strong API, CLI, dashboard, audit, and policy-as-code interfaces.
- Enterprise identity, authorization, tenant isolation, and SIEM integration.
- Automated test, smoke, end-to-end, full-platform stress, soak, security, performance, recovery, and documentation gates.

CDNLite is not intended to claim immediate parity with hyperscale public CDN providers. The near-term goal is a reliable private CDN for controlled deployments, followed by production hardening, enterprise controls, and deployment ecosystem growth.

---

## 2. Current product baseline

The repository already contains important foundations:

- PHP control-plane API, CLI, schedulers, and jobs.
- PostgreSQL state and analytics storage.
- Vue and TypeScript dashboard.
- OpenResty and Lua edge proxy.
- Signed edge-agent registration, heartbeat, configuration polling, metrics, and security-event delivery.
- PowerDNS and DNSGeo integration.
- Domain, DNS, origin, cache, WAF, rate-limit, IP rule, redirect, response-header, SSL, analytics, event, and audit workflows.
- Docker Compose and split-deployment examples.
- CI jobs for static validation, smoke, end-to-end, DNS end-to-end, and DNS stress qualification.
- Operator and developer documentation.

The main problem is not a complete absence of features. The main problem is that some features are only partially connected across the database, API, snapshot, edge, telemetry, dashboard, and runtime-test layers. The data model and reporting path also need explicit workload separation and scale limits, and the existing specialized stress checks need to become a full-platform qualification system.

The first roadmap objective is therefore to remove misleading partial implementations and turn existing capabilities into complete, measurable workflows.

---

## 3. Roadmap principles

1. **Runtime behavior is the source of truth.**  
   A selectable action is not complete until the promised behavior happens in a running stack.

2. **Complete features end to end.**  
   Relevant work must include persistence, backend validation, API contracts, snapshot generation, edge enforcement, dashboard behavior, telemetry, tests, documentation, and operations.

3. **Reliability comes before expansion.**  
   Unbounded analytics, expensive edge hot paths, incomplete challenges, unsafe overload handling, and cache correctness are higher priority than new packaging or integrations.

4. **All request paths must be bounded.**  
   Database queries, response sizes, queues, logs, event production, retries, backfills, request bodies, cookies, tokens, and polling must have explicit limits.

5. **Safe defaults are mandatory.**  
   New installations should not enable unsafe, misleading, or resource-unbounded behavior.

6. **Fail safely and preserve the last known good state.**  
   Broken configuration, telemetry outages, control-plane outages, or external service failures must not unnecessarily break healthy cached traffic.

7. **Security decisions have explicit precedence.**  
   Administrative blocks and explicit deny rules must override clearance, cache, waiting-room admission, or convenience behavior.

8. **Transactional and reporting workloads must not compete without limits.**  
   Control-plane writes, event ingestion, rollups, dashboard queries, exports, and maintenance jobs need separate budgets, pools, schemas, and operational visibility.

9. **Stress testing validates recovery as well as peak throughput.**  
   A stress run is incomplete until load stops, queues drain, error rates recover, data remains consistent, and smoke and end-to-end checks pass again.

10. **Documentation is part of implementation.**  
   User, operator, API, architecture, security, troubleshooting, upgrade, rollback, and runbook documentation must change in the same pull request.

11. **Every phase produces evidence.**  
   A phase cannot become Complete without automated test results, smoke and end-to-end evidence, and applicable performance or failure testing.

12. **Roadmap progress changes with the code.**  
    Milestones, evidence links, known limitations, and phase status must be updated in the same pull request that changes the implementation.

---

## 4. Priority levels

| Priority | Meaning |
| --- | --- |
| **P0** | Correctness, security, scaling, or availability work required before increasing production exposure. |
| **P1** | Core CDN capability and operational hardening required for dependable controlled production. |
| **P2** | Enterprise administration, identity, isolation, resilience, and repeatability. |
| **P3** | Deployment ecosystem, advanced CDN services, provider features, and project expansion. |
| **P4** | Optional long-term capabilities that must not delay the private-CDN core. |

A lower-priority phase may begin early only when it does not delay a P0 or P1 exit gate.

---

## 5. Phase statuses

Use one status for every phase:

- **Proposed** — direction is documented but not yet approved.
- **Planned** — scope, owner, and acceptance criteria are approved.
- **In progress** — implementation has started.
- **Validation** — implementation is finished and exit-gate validation is running.
- **Blocked** — a documented dependency or decision prevents progress.
- **Complete** — all required implementation, validation, documentation, and evidence gates passed.
- **Deferred** — intentionally removed from active execution with a documented reason.

Each phase must record:

- Owner
- Tracking issue or project
- Pull requests
- Last progress update
- Completed milestones
- Remaining milestones
- Blockers
- Test evidence
- Smoke evidence
- End-to-end evidence
- Stress, performance, security, or failure evidence
- Documentation links
- Rollout notes
- Rollback notes
- Known limitations

---

## 6. Canonical roadmap and progress synchronization

`ROADMAP.md` is the canonical roadmap.

`docs/roadmap.md` must be generated from or synchronized with this file while preserving the VitePress front matter required by the documentation site.

Add CI validation such as:

```text
ROADMAP.md
docs/roadmap.md
ci/check-roadmap-sync.sh
ci/check-roadmap-evidence.sh
ci/reports/roadmap/README.md
ci/reports/roadmap/phase-XX.md
```

CI must fail when:

- `ROADMAP.md` and `docs/roadmap.md` drift.
- A phase is marked Complete without an evidence report.
- A Complete phase still has an unchecked mandatory exit gate.
- A user-visible change does not update `CHANGELOG.md`.
- An API change does not update OpenAPI and API documentation.
- A runtime feature is marked complete using only source-string assertions.
- An unsupported production control remains enabled without a clear experimental label.

---

## 7. Definition of done for every phase

A phase may be marked **Complete** only when all applicable items are complete.

### 7.1 Implementation

- [ ] Authoritative fresh-install database schema is updated.
- [ ] Database constraints and indexes match actual access patterns.
- [ ] Database changes include representative query plans, growth assumptions, retention impact, and rollback strategy.
- [ ] Transactional, ingestion, job, and reporting workloads have explicit connection and query budgets.
- [ ] Backend services and domain logic are implemented.
- [ ] API validation and stable error contracts are implemented.
- [ ] OpenAPI and generated client types are synchronized.
- [ ] Edge configuration snapshots contain validated settings.
- [ ] Edge or agent runtime enforcement is implemented where applicable.
- [ ] Dashboard controls and state handling are implemented.
- [ ] Metrics, security events, audit events, and health visibility are implemented.
- [ ] Failure, timeout, retry, cancellation, and recovery paths are implemented.
- [ ] Unsupported, dead, duplicated, shadowed, or placeholder behavior is removed.
- [ ] Resource and input bounds are explicit.
- [ ] Safe rollout and rollback behavior exists.

### 7.2 Automated tests

- [ ] PHP syntax validation passes.
- [ ] Backend unit and integration tests pass.
- [ ] Database fresh-install and schema tests pass.
- [ ] Dashboard type checking passes.
- [ ] Dashboard component and state tests pass.
- [ ] Dashboard production build passes.
- [ ] Lua syntax and runtime tests pass.
- [ ] Edge-agent shell syntax and behavior tests pass.
- [ ] Negative, boundary, authorization, and failure-recovery tests exist.
- [ ] Source-contract tests are not the only evidence for runtime behavior.

### 7.3 Runtime validation

- [ ] Root Docker Compose configuration validates.
- [ ] The complete root stack starts from a clean state.
- [ ] Core, edge, dashboard, database, DNS, and required worker health checks pass.
- [ ] `ci/smoke.sh` includes phase-specific assertions and passes.
- [ ] `ci/e2e.sh` includes phase-specific workflow coverage and passes.
- [ ] `ci/dns_e2e.sh` passes when DNS, TLS, routing, health, or records are affected.
- [ ] Applicable stress, load, benchmark, restart, or failure-injection tests pass.
- [ ] The feature stress matrix is updated for every changed runtime capability.
- [ ] Post-stress recovery checks prove queues drain, jobs recover, data remains consistent, and smoke/e2e still pass.
- [ ] CI uploads logs and machine-readable and human-readable evidence.
- [ ] The release gate depends on all mandatory phase checks.

### 7.4 Documentation and release hygiene

- [ ] User guide is updated.
- [ ] Administrator/operator guide is updated.
- [ ] API and OpenAPI documentation are updated.
- [ ] Architecture documentation is updated.
- [ ] Security model and threat model are updated.
- [ ] Environment variables and examples are updated.
- [ ] Deployment and topology documentation are updated.
- [ ] Troubleshooting and runbooks are updated.
- [ ] Upgrade, rollout, rollback, and compatibility notes are present.
- [ ] `CHANGELOG.md` is updated.
- [ ] `ROADMAP.md` and `docs/roadmap.md` progress are updated.
- [ ] Known limitations are stated honestly.

### 7.5 Standard validation commands

Run the applicable repository checks:

```bash
docker compose config --quiet

find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests

sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh

bash -n ci/agent_flow_checks.sh
bash -n ci/smoke.sh
bash -n ci/e2e.sh
bash -n ci/dns_e2e.sh
bash -n ci/stress-dns.sh
# After Phase 15 introduces the common runner:
bash -n ci/stress-platform.sh
bash -n ci/powerdns_dns_checks.sh

(
  cd dash
  npm ci
  npm run typecheck
  npm test
  npm run build
)

(
  cd docs
  npm ci
  npm run docs:build
)

docker compose up -d --build --wait
./ci/smoke.sh
./ci/e2e.sh
CDNLITE_EDGE_HEALTH_MODE=static ./ci/dns_e2e.sh
# Disposable environment only after the common stress runner exists:
CDNLITE_ALLOW_DESTRUCTIVE_STRESS=1 ./ci/stress-platform.sh --profile release
```

Run destructive and high-volume tests only against an explicitly disposable environment.

---


## 8. Roadmap overview

| Phase | Priority | Status | Main result |
| --- | --- | --- | --- |
| 0. Governance and capability audit | P0 | Proposed | One truthful capability inventory and enforceable completion gates |
| 1. Database architecture and real-time reporting foundation | P0 | Proposed | Fast operational reads, scalable event ingestion, bounded reporting, and durable rollups |
| 2. Analytics scalability and asynchronous aggregation | P0 | Proposed | Bounded, fast, cancellable analytics APIs and dashboard views |
| 3. Edge hot-path performance and bounded telemetry | P0 | Proposed | No repeated config parsing or synchronous per-request telemetry writes |
| 4. Real challenge and clearance system | P0 | Proposed | Challenge actions perform a real verifiable workflow |
| 5. Adaptive overload protection and waiting room | P0 | Proposed | Origins remain protected under attack or heavy usage |
| 6. Cache correctness foundation | P0 | Proposed | Standards-aware cache keys, eligibility, revalidation, and stale behavior |
| 7. Origin routing, resilience, and shielding | P0 | Proposed | Predictable health, load balancing, failover, retries, and circuit breaking |
| 8. Purge and invalidation platform | P1 | Proposed | Fast, safe, observable purge by URL, prefix, host, and tag |
| 9. Edge protocol and delivery performance | P1 | Proposed | Efficient TLS, HTTP/2, optional HTTP/3, compression, and connection reuse |
| 10. DNS and GeoDNS reliability | P1 | Proposed | Deterministic DNS publication and health-aware routing |
| 11. TLS and certificate lifecycle | P1 | Proposed | Reliable issuance, renewal, activation, storage, and expiry visibility |
| 12. WAF, rate limiting, API protection, and abuse defense | P1 | Proposed | Complete, testable security actions with safe precedence |
| 13. Observability, analytics operations, and alerting | P1 | Proposed | Prometheus, dashboards, tracing identifiers, alerts, and bounded exports |
| 14. Dashboard, API, CLI, and onboarding quality | P1 | Proposed | Fast, understandable, recoverable operator workflows |
| 15. Full-platform stress, soak, scale, and recovery qualification | P1 | Proposed | Every major CDN capability has reproducible capacity and recovery evidence |
| 16. Secret, token, supply-chain, and release security | P1 | Proposed | Rotatable credentials and verifiable releases |
| 17. Backup, restore, disaster recovery, and control-plane HA | P2 | Proposed | Tested recovery and documented resilience |
| 18. RBAC and scoped API keys | P2 | Proposed | Least-privilege administration and automation |
| 19. OIDC, SAML, sessions, and enterprise identity | P2 | Proposed | Native external identity integration |
| 20. Tenant isolation, quotas, and SIEM boundaries | P2 | Proposed | Explicit ownership and cross-tenant isolation |
| 21. Policy as code and managed presets | P2 | Proposed | Repeatable, reviewable, versioned CDN policy |
| 22. Kubernetes, Helm, Terraform, and fleet automation | P3 | Proposed | Repeatable deployment beyond Compose |
| 23. Advanced CDN services | P3 | Proposed | Image optimization, signed delivery, prefetch, and optional edge extensions |
| 24. Hosting-provider and commercial platform features | P4 | Proposed | Optional plans, quotas, usage export, and reseller workflows |
| 25. Contributor and ecosystem maturity | P3 | Proposed | Clear extension paths and safe contribution workflows |

---

# P0 — Finish and stabilize the CDN core

## Phase 0 — Governance and capability audit

### Objective

Create a truthful inventory of what CDNLite configures, persists, distributes, enforces, observes, tests, and documents.

### Why this phase is first

Several product surfaces can expose actions before their complete runtime behavior exists. This creates operational and security risk because an operator may believe a feature is protecting or accelerating a website when it is only storing configuration or returning a generic block.

### Scope

Create a capability matrix with these columns:

- Feature
- Dashboard control
- API contract
- Persisted state
- Configuration snapshot
- Edge enforcement
- Core worker enforcement
- Metrics/events
- Audit event
- Unit/integration test
- Smoke test
- End-to-end test
- Documentation
- Current status
- Owner
- Known limitation

Audit at minimum:

- Domains and activation
- Nameserver verification
- DNS-only and proxied records
- GeoDNS
- Origin pools
- Origin health
- Origin failover
- TLS and certificates
- Cache settings
- Cache rules
- Cache keys
- Cache bypass
- Cache revalidation
- Cache purge
- Redirects
- Response headers
- Page rules
- WAF matches and actions
- Bot classification and actions
- Rate-limit matches and actions
- IP access rules
- Geo restrictions
- Challenge
- Overload protection
- Maintenance mode
- Edge registration
- Heartbeats
- Config polling and apply
- Config rollback
- Metrics ingestion
- Security-event ingestion
- Audit logs
- Analytics filtering
- Analytics recalculation
- Retention pruning
- Recommendations and protection profiles
- Alerts and exports

For incomplete features:

1. Complete the runtime path, or
2. Disable or hide the production control, or
3. Mark it Experimental with explicit limitations.

### Deliverables

- Capability matrix in repository documentation.
- Machine-readable capability manifest where practical.
- CI check for unsupported active controls.
- Roadmap evidence format.
- Roadmap sync check.
- Updated contribution and pull-request templates.
- Product maturity statement linked from the dashboard and documentation.

### Acceptance criteria

- Every user-visible feature has an owner and status.
- No `challenge`, `throttle`, `failover`, `async`, `purge`, or `protected` label misrepresents runtime behavior.
- Every stable feature has at least one runtime workflow test.
- The root and docs roadmaps remain synchronized.
- Completion evidence is required by CI.

### Validation

#### Tests

- Capability manifest schema tests.
- Roadmap sync and evidence-check tests.
- Tests proving unsupported features are hidden or marked Experimental.

#### Smoke

- Verify the production dashboard bundle does not expose unsupported stable controls.
- Verify the API exposes stable capability metadata.

#### End to end

- Configure representative cache, WAF, rate-limit, DNS, TLS, and routing actions.
- Confirm the matrix accurately describes the resulting runtime behavior.

#### Documentation

- Roadmap governance.
- Capability status guide.
- Contribution guide.
- Testing guide.
- Release process.
- Maturity and limitation statements.

---


## Phase 1 — Database architecture and real-time reporting foundation

### Objective

Redesign the data layer so control-plane operations remain fast while high-volume edge telemetry, security events, analytics, exports, and historical reports grow.

This phase establishes the storage architecture used by Phase 2 analytics and every later feature that creates operational or reporting data.

### Problems to solve

- Dashboard and API reads become slower as historical data grows.
- Reporting queries can compete with domain, DNS, origin, certificate, and security configuration writes.
- High-volume event tables can become expensive to index, vacuum, aggregate, retain, and query.
- Global dashboards can accidentally scan complete history.
- Synchronous aggregation and export work can hold API requests open.
- Current-state questions and historical-reporting questions use different access patterns but may query the same large tables.
- Duplicate telemetry delivery, late events, retries, and interrupted jobs require explicit consistency rules.
- Database performance has no single documented schema model, workload budget, query budget, or capacity qualification.

### Architecture principles

#### Separate workload classes

Treat these as distinct workloads:

1. **Transactional control-plane data**
   - Users and access.
   - Domains.
   - DNS desired state.
   - Origins and pools.
   - Cache and security policy.
   - TLS metadata.
   - Edge inventory.
   - Jobs and audit state.

2. **High-volume append-only operational data**
   - Request usage events.
   - Cache outcomes.
   - Origin measurements.
   - Security events.
   - Challenge and waiting-room events.
   - Edge health samples.
   - DNS and TLS operational events.

3. **Current-state read models**
   - Current edge status.
   - Current origin health.
   - Current DNS sync status.
   - Current certificate status.
   - Current config version.
   - Recent platform counters.

4. **Historical reporting models**
   - Minute, hour, and day rollups.
   - Per-domain and global summaries.
   - Security summaries.
   - Cache and origin efficiency.
   - Capacity and usage reports.

5. **Long-running maintenance workloads**
   - Backfill.
   - Retention pruning.
   - Export.
   - Reconciliation.
   - Reindex or partition maintenance.
   - Backup and restore.

Use separate connection pools, database roles, statement timeouts, concurrency limits, and worker queues for these workloads even when they initially use one PostgreSQL cluster.

#### PostgreSQL-first, benchmark-driven evolution

Use PostgreSQL as the authoritative starting point and improve its design before adding another database.

Do not add an analytics database only because reports are slow. First implement:

- Correct schemas.
- Bounded queries.
- Native time partitioning where justified.
- Incremental rollups.
- Proper indexes.
- Batch ingestion.
- Read models.
- Read-only reporting roles.
- Connection and statement budgets.
- Query caching.
- Retention.

After reproducible benchmarks, create an architecture decision record if PostgreSQL cannot meet documented targets. The evaluation may compare:

- PostgreSQL primary plus read replica.
- Native PostgreSQL partitions.
- PostgreSQL extensions only when operationally acceptable.
- A separate column-oriented analytics store.
- A durable event stream plus analytics consumer.

Any additional store must define:

- Source of truth.
- Delivery guarantees.
- Replay.
- Deduplication.
- Backfill.
- Consistency delay.
- Failure behavior.
- Upgrade and backup.
- Operational cost.
- Removal or rollback path.

### Scope

#### Canonical data model

Create and document a canonical data model with clear ownership.

Transactional tables should:

- Use stable primary keys.
- Use foreign keys where lifecycle and performance permit.
- Use unique constraints for domain invariants.
- Use explicit timestamps.
- Store normalized identifiers.
- Avoid large unbounded JSON documents for fields frequently filtered or grouped.
- Use JSONB only for flexible attributes with validated schemas and bounded size.
- Use optimistic versioning where concurrent control-plane edits can conflict.
- Record creation and update actors for sensitive state.

Operational event tables should:

- Be append-only under normal operation.
- Have a stable event ID or deduplication key.
- Include event time and ingest time separately.
- Include domain and edge identifiers where applicable.
- Use bounded event payloads.
- Store frequently queried dimensions in typed columns.
- Store rare or evolving attributes in bounded JSONB.
- Define late-event behavior.
- Define duplicate-event behavior.
- Avoid synchronous foreign-key checks that make high-volume ingestion fragile when a dimension is temporarily unavailable; use validated IDs and reconciliation where justified.
- Preserve tenant isolation requirements for future phases.

Current-state tables should:

- Contain one current row per resource or bounded key.
- Be updated idempotently.
- Include source event/version and update time.
- Support fast dashboard summary queries.
- Never require scanning historical event tables to answer basic health questions.

Aggregate tables should:

- Use explicit bucket start in UTC.
- Include domain ID and required dimensions.
- Use unique constraints that support idempotent upsert.
- Track source watermark and update time.
- Keep minute, hour, and day data separate when their retention differs.
- Avoid storing dimensions that create uncontrolled cardinality.
- Permit bounded per-domain and global queries.

#### Suggested logical schemas

Use PostgreSQL schemas or an equivalent clear naming convention, for example:

```text
control        transactional configuration and identity
operations     jobs, current health, reconciliation, active versions
telemetry      raw append-only usage and operational events
reporting      minute/hour/day aggregates and read models
audit          immutable or append-oriented audit records
```

The exact names may differ, but ownership and query boundaries must be documented.

#### Event identity and ingestion

Implement:

- Agent or edge event IDs.
- Batch ID.
- Source edge ID.
- Event sequence or source timestamp where available.
- Idempotent batch acceptance.
- Duplicate detection.
- Maximum batch count.
- Maximum compressed and uncompressed batch size.
- Maximum event size.
- Partial-batch failure policy.
- Stable rejection reasons.
- Ingest timestamp.
- Dead-letter or rejected-event diagnostics with strict retention.
- Backpressure signals.
- Bounded retry with jitter.

Prefer micro-batch ingestion over one database transaction per request event.

Evaluate and benchmark:

- Multi-row inserts.
- PostgreSQL `COPY` through a controlled ingestion path.
- Staging tables followed by validated merge.
- Asynchronous ingestion workers.

The API receiving telemetry should acknowledge only according to documented durability semantics.

#### Native partitioning

For high-volume time-series tables, evaluate native PostgreSQL range partitioning by event time or ingest time.

Requirements:

- Partition interval chosen from measured volume.
- Automated future partition creation.
- Safe old-partition detach/drop.
- No request-time partition creation.
- Partition-pruning tests.
- Index strategy per partition.
- Late-event policy for closed partitions.
- Default-partition monitoring if used.
- Backup and restore behavior.
- Migration path from existing unpartitioned tables.
- Rollback plan.

Do not partition small transactional tables merely for consistency of style.

#### Index design

Create an index inventory with:

- Query supported.
- Expected selectivity.
- Write cost.
- Storage cost.
- Retention interaction.
- Evidence from query plans.

Typical candidates to validate include:

- B-tree on `(domain_id, event_time DESC)`.
- B-tree on `(domain_id, bucket, bucket_start DESC)`.
- B-tree on job state and scheduled time.
- B-tree on current-state resource keys.
- Partial indexes for active or failed jobs.
- BRIN on large append-only timestamp columns.
- GIN only for JSONB paths that have demonstrated query demand.
- Unique indexes for event deduplication and aggregate upsert.

Remove redundant and unused indexes after measured observation and safe rollout.

#### Read models and real-time summaries

Create read models for fast dashboard cards and status pages.

Examples:

- Requests in the most recent rolling window.
- Cache hit ratio.
- Current origin health.
- Current active edge count.
- Current WAF/rate/challenge counters.
- Current telemetry lag.
- Current aggregation lag.
- Current DNS/SSL/job failure counts.

Real-time views should combine:

- A small recent-data tail.
- Pre-aggregated historical buckets.

Do not scan full raw tables to display current summary cards.

Define freshness classes:

| Class | Example | Target |
| --- | --- | --- |
| Operational current state | edge/config/origin health | seconds |
| Near-real-time counters | traffic/security/cache cards | normally within 15–30 seconds |
| Interactive analytics | charts and comparisons | normally within 60 seconds |
| Historical reports | hour/day reports and exports | documented job-based freshness |

Targets must be validated on the reference environment before being treated as release guarantees.

#### Incremental aggregation

Implement a durable aggregation pipeline with:

- Per-stream or per-domain watermarks.
- Small overlap windows for late events.
- Idempotent upserts.
- Bounded batch sizes.
- Minute rollups from raw events.
- Hour rollups from minute data.
- Day rollups from hour data.
- Progress and lag metrics.
- Retry and dead-job detection.
- Job locking.
- Per-domain backfill.
- Bounded time-range backfill.
- Reconciliation checks between raw and aggregate totals.
- Correction records or deterministic reprocessing.
- No global delete-and-rebuild in a normal API request.

#### Reporting query service

Create a dedicated reporting service or module with:

- Mandatory time ranges.
- Maximum returned points.
- Dimension allowlists.
- Query cost validation.
- Per-query statement timeout.
- Read-only database role.
- Separate reporting connection pool.
- Maximum concurrent expensive queries.
- Cursor pagination for event lists.
- Asynchronous export for large reports.
- Query result cache.
- ETag or equivalent validation.
- Query IDs for diagnostics.
- Cancellation when the client disconnects where supported.
- Safe global-query limits.
- Future tenant scope.

Reporting queries must not use unrestricted dynamic SQL from client-provided field names.

#### Connection and transaction management

Define separate budgets for:

- API transactional pool.
- Telemetry ingest pool.
- Background job pool.
- Reporting read pool.
- Administrative maintenance pool.

Add:

- Connection acquisition timeout.
- Statement timeout by workload.
- Idle transaction timeout.
- Lock timeout.
- Maximum transaction duration guidance.
- Retry classification for serialization/deadlock failures.
- No network calls while holding database transactions unless explicitly justified.
- Transaction size limits for bulk work.
- Pool saturation metrics.
- Long-running transaction alerts.

#### PostgreSQL operations

Add and document:

- `pg_stat_statements`.
- Slow-query logging with safe thresholds.
- Autovacuum monitoring.
- Table and index bloat monitoring.
- Dead tuple monitoring.
- Checkpoint and WAL monitoring.
- Replication lag metrics when replicas are added.
- Disk-growth forecasts.
- Partition count.
- Lock waits.
- Connection saturation.
- Backup impact.
- Maintenance windows.
- Safe `ANALYZE`.
- Online index creation where supported.
- Schema-change lock assessment.

#### Schema migration strategy

Every material database change must include:

- Fresh-install schema update.
- Upgrade migration when compatibility requires it.
- Data backfill plan.
- Online versus maintenance-window classification.
- Expected lock level.
- Estimated runtime on reference data.
- Retry and resume behavior.
- Rollback or forward-fix plan.
- Mixed-version application behavior during rollout.
- Verification query.
- Post-migration cleanup.

Large rewrites must not run inside a normal web request.

#### Retention and archive

Define retention separately for:

- Raw usage.
- Raw security events.
- Operational events.
- Minute aggregates.
- Hour aggregates.
- Day aggregates.
- Audit.
- Job logs.
- Rejected telemetry.
- Export files.

Implement:

- Bounded pruning jobs.
- Partition drop where applicable.
- Dry-run and preview.
- Per-domain or future per-tenant policy within platform limits.
- Legal or incident hold foundation where later required.
- Storage forecast.
- Archive/export before deletion where configured.
- Deletion audit.

#### Data correctness

Create automated reconciliation for:

- Accepted events versus stored events.
- Raw totals versus minute rollups.
- Minute versus hour.
- Hour versus day.
- Per-domain versus global totals.
- Purge, security, challenge, and overload counters where applicable.

Report:

- Missing ranges.
- Duplicate rates.
- Late-event rates.
- Rollup corrections.
- Watermark lag.
- Reconciliation error.

#### Read replicas and future scale-out

After the primary design is measured:

- Support an optional read replica for historical reports.
- Route only replica-safe queries.
- Expose replication lag.
- Avoid serving freshness-sensitive current state from a stale replica without clear metadata.
- Fall back safely or fail explicitly.
- Keep control-plane writes on the primary.
- Test failover and recovery.
- Document that a read replica does not solve inefficient queries.

### Deliverables

- Database architecture document.
- Entity and event data dictionary.
- Workload and connection-pool matrix.
- Partition and retention design.
- Index inventory.
- Query-budget policy.
- Real-time current-state read models.
- Batch telemetry ingestion.
- Incremental aggregation foundation.
- Reporting query service.
- Reconciliation jobs.
- Database health metrics and dashboards.
- Schema migration runbook.
- Capacity benchmark generator.
- Architecture decision record for PostgreSQL-only versus optional analytics-store evolution.

### Acceptance criteria

- Control-plane writes remain responsive while reporting and ingestion load run.
- Basic dashboard health and summary cards do not scan full historical tables.
- Reporting endpoints have bounded time, rows, points, dimensions, and execution time.
- Telemetry retries do not create incorrect duplicate totals.
- Rollups are idempotent and recover after interruption.
- Database pools cannot be exhausted by one workload class without visibility and configured limits.
- Retention jobs are resumable and bounded.
- Representative query plans use partition pruning and intended indexes.
- Reconciliation detects introduced missing or duplicate data.
- No normal API request performs a full database-wide analytics rebuild.
- The documented reference dataset meets approved latency and ingestion targets.

### Initial performance targets

Establish final targets through benchmarks. Initial qualification targets:

- Common control-plane reads p95 below 250 ms at the API service boundary.
- Common control-plane writes p95 below 500 ms, excluding external DNS/ACME work.
- Current-state dashboard summary queries p95 below 500 ms.
- Cached report queries p95 below 1.5 seconds.
- Cold bounded interactive reports p95 below 3 seconds.
- Near-real-time reporting freshness normally below 30 seconds.
- Interactive analytics freshness normally below 60 seconds.
- Telemetry ingestion sustains the documented reference event rate without unbounded queue growth.
- Reporting load does not increase control-plane API p95 beyond an approved regression budget.
- Recovery after worker restart does not duplicate aggregate totals.

These numbers are targets for a documented reference environment, not universal promises.

### Validation

#### Unit and integration tests

- Schema constraints.
- Event deduplication.
- Batch limits.
- Partial-batch behavior.
- Partition routing.
- Partition pruning.
- Late events.
- Aggregate upserts.
- Watermark recovery.
- Reconciliation.
- Retention.
- Reporting cost limits.
- Query cancellation.
- Connection-pool isolation.
- Deadlock/serialization retry.
- Migration resume.
- Read-replica freshness metadata when implemented.

#### Smoke

- Fresh database installs.
- Database health endpoint.
- Current-state reads.
- Telemetry batch ingest.
- Minute rollup.
- Bounded reporting query.
- Retention dry run.
- `pg_stat_statements` or equivalent diagnostic availability in supported environments.

#### End to end

```text
edge traffic
  -> agent batch
  -> telemetry ingest
  -> raw event storage
  -> current-state update
  -> minute rollup
  -> reporting API
  -> dashboard
  -> reconciliation
```

Also test:

- Duplicate batch replay.
- Late event.
- Worker restart.
- Database restart.
- Bounded backfill.
- Retention.
- Optional replica lag.

#### Database benchmark

Generate reproducible datasets with at least:

- 100 and 1,000 domains.
- Multiple edges per domain.
- 10 million, 100 million, and a projected larger raw-event profile where hardware permits.
- Security events.
- Origin health history.
- Jobs and audit data.
- Minute, hour, and day aggregates.

Record:

- Ingest events per second.
- Batch latency.
- Query p50/p95/p99.
- Rows scanned.
- Shared-buffer hits and reads.
- WAL volume.
- CPU.
- Memory.
- Disk growth.
- Index size.
- Autovacuum behavior.
- Lock waits.
- Pool saturation.
- Rollup lag.
- Recovery time.
- Reconciliation result.

Use `EXPLAIN (ANALYZE, BUFFERS)` on representative safe test data and store sanitized reports.

#### Stress and soak

- Continuous telemetry ingestion.
- Concurrent control-plane writes.
- Concurrent dashboard reports.
- Backfill under load.
- Retention under load.
- Database restart.
- Worker restart.
- Reporting cancellation.
- Connection saturation.
- Slow query.
- Lock contention.
- Disk-pressure warning.
- At least one extended soak run to detect growth, bloat, leaked connections, and increasing lag.

#### Documentation

- Database architecture.
- Data dictionary.
- Workload classes.
- Query budgets.
- Partitioning.
- Indexing.
- Ingestion semantics.
- Rollup consistency.
- Real-time freshness.
- Retention.
- Capacity planning.
- Monitoring.
- Migration.
- Backup/restore impact.
- Troubleshooting.
- Rollout and rollback.

---

## Phase 2 — Analytics scalability and asynchronous aggregation

### Objective

Make global and per-domain analytics bounded, fast, cancellable, and predictable as event volume grows.

### Problems to solve

- Unbounded historical aggregate scans.
- Large global queries across all domains.
- Synchronous delete-and-rebuild recalculation.
- Dashboard requests that race or cannot be cancelled reliably.
- Initial page load blocked by unrelated data.
- No explicit point-count or date-range budget.
- Partial failures blanking an entire analytics page.
- Data retention and freshness not clearly reported.

### Scope

#### API contracts

Add or standardize:

- `domain_id`
- `from`
- `to`
- `bucket`
- `limit_points`
- `timezone`
- optional comparison range
- optional dimensions with strict allowlists

Requirements:

- Default to the last 24 hours.
- Automatically choose a safe bucket when omitted.
- Maximum 500 points per time series by default.
- Reject excessive ranges or dimension combinations.
- Return:
  - Effective range
  - Bucket
  - Point count
  - Data freshness
  - Aggregation watermark
  - Partial-data flag
  - Query identifier
  - Cache status
- Keep old endpoints compatible only through safe bounded defaults.

#### Aggregation pipeline

Replace synchronous full recalculation with:

- Durable aggregation watermarks.
- Incremental minute rollups.
- Hour rollups from minute data.
- Day rollups from hour data.
- Idempotent upserts.
- Per-domain and bounded-range backfills.
- Job locks and ownership.
- Retry state.
- Recovery after interrupted jobs.
- Job progress and failure details.
- Raw and aggregate retention policies.
- Safe late-arriving-event handling.
- Time-zone-independent storage and explicit presentation conversion.

Dashboard recalculation must:

- Return `202 Accepted`.
- Return a job ID.
- Never hold the HTTP request while rebuilding.
- Show progress, failures, and completion.
- Permit cancellation only when cancellation is safe.

#### Database

- Inspect representative `EXPLAIN (ANALYZE, BUFFERS)` output.
- Add indexes based on actual query shapes.
- Add unique constraints required for idempotent rollups.
- Consider timestamp BRIN indexes for large append-only tables.
- Avoid redundant indexes that slow ingestion.
- Partition large event tables only after measuring need.
- Add bounded data-pruning jobs.
- Record query-plan and table-growth evidence.

#### API result caching

- Cache normalized analytics queries for a short period.
- Add ETag or equivalent validation.
- Support stale-while-revalidate where practical.
- Invalidate or refresh after rollup completion.
- Prevent cross-domain or future cross-tenant cache leakage.

#### Dashboard

- Do not block first render on a complete domain list.
- Use server-paginated searchable selectors.
- Load summary cards first.
- Lazy-load charts.
- Preserve stale data while refreshing.
- Display per-panel error states.
- Debounce filter changes or use Apply.
- Cancel obsolete requests.
- Combine caller cancellation and timeout signals.
- Distinguish cancellation, timeout, network failure, authorization failure, and server failure.
- Cache results by normalized query.
- Display freshness and partial-data metadata.
- Add progressive skeletons without hiding existing results.

### Performance targets

On a documented reference environment:

- No default query scans unlimited history.
- Maximum 500 points per returned series.
- Cached analytics API p95 below 1.5 seconds.
- Cold analytics API p95 below 3 seconds.
- Dashboard remains usable with at least:
  - 100 domains
  - 10 million raw usage rows
  - 1 million security events
- Obsolete browser requests are cancelled.
- Full aggregate rebuild is never performed synchronously through a dashboard request.

Targets are release gates only after the benchmark environment and dataset are checked into the repository or reproducibly generated.

### Deliverables

- Bounded analytics API.
- Incremental rollup workers.
- Job status API.
- Dashboard progressive loading.
- Query caching and freshness metadata.
- Benchmark generator and report.
- Retention documentation.
- Operations runbook.

### Acceptance criteria

- All analytics views have explicit bounded ranges.
- Recalculation is asynchronous.
- Rollups recover after interruption.
- Duplicate processing does not corrupt totals.
- Partial dashboard failure does not erase healthy panels.
- Benchmark targets pass or documented release-blocking exceptions are approved.

### Validation

#### Tests

- Range and bucket validation.
- Point-limit enforcement.
- Watermark advance and recovery.
- Idempotent upsert behavior.
- Late event handling.
- Cache-key isolation.
- Cache invalidation.
- API cancellation.
- Dashboard partial errors and stale-data refresh.

#### Smoke

- Default summaries return bounded metadata.
- Job creation and job status endpoints work.
- Dashboard analytics route loads without fetching unlimited history.

#### End to end

- Edge traffic produces metrics.
- Agent pushes metrics.
- Core ingests data.
- Incremental rollup runs.
- API returns the result.
- Dashboard renders the result.
- A bounded backfill updates the same view.

#### Performance

- Reproducible large dataset.
- p50, p95, and p99 latency.
- Rows scanned.
- Buffer reads.
- Memory use.
- Ingestion impact.
- Dashboard request count and cancellation evidence.

#### Documentation

- Analytics API and OpenAPI.
- Data model.
- Bucketing and freshness.
- Retention.
- Backfills.
- Job operations.
- Performance benchmark.
- Troubleshooting.
- Rollout and rollback.

---

## Phase 3 — Edge hot-path performance and bounded telemetry

### Objective

Remove repeated filesystem and parsing work from the edge request path and ensure telemetry remains bounded during normal traffic and attacks.

### Problems to solve

- Configuration read and JSON decode work in request processing.
- Per-request telemetry file open, append, and close behavior.
- Event floods during attacks.
- Weak visibility into active configuration age and reload errors.
- Limited production capacity defaults.
- Telemetry outages potentially affecting disk or memory growth.

### Scope

#### Worker configuration cache

Implement:

- Parsed configuration stored per worker.
- Timer-driven refresh or version/modification detection.
- Atomic snapshot replacement.
- Last-known-good snapshot.
- Strict snapshot schema validation.
- Maximum accepted snapshot size.
- Configuration version and checksum.
- Active load time.
- Maximum configuration-age warning.
- Reload success and failure counters.
- Last reload error.
- Safe manual reload endpoint or signal.
- Startup behavior when no valid snapshot exists.
- Bounded retry with jitter.

A malformed new snapshot must not replace a healthy active snapshot.

#### Buffered metrics and security events

Implement:

- Bounded in-memory queues or shared queues.
- Timer-based batch flush.
- Configurable batch size and interval.
- Maximum queue length and byte size.
- Drop policy and drop counters.
- Flush success and failure counters.
- Backoff with jitter.
- Shutdown flush where supported.
- Attack-event sampling or aggregation.
- Repetitive-event deduplication.
- Disk-spool fallback only with explicit size and retention limits.
- Queue corruption detection and recovery.
- No secrets, full tokens, or full cookies in telemetry.

Telemetry failure must not stop customer traffic.

#### Connection and worker capacity

Make configurable:

- Worker process count.
- Worker connections.
- Shared dictionary sizes.
- DNS resolver settings.
- Upstream keepalive.
- Connect, read, and send timeouts.
- Header and body buffer limits.
- Request body limits.
- Logging level.
- Queue limits.
- TLS session settings.

Use:

- Safe production defaults.
- Deterministic low-resource test defaults.
- Readiness warnings for clearly inadequate production settings.

#### Request-path profiling

Add a repeatable benchmark for:

- Cache hit.
- Cache miss.
- WAF evaluation.
- Rate-limit evaluation.
- Redirect.
- Origin proxy.
- Telemetry enabled and disabled.
- Configuration reload.
- Multi-domain lookup.

Record edge-added latency and throughput before and after the phase.

### Performance targets

On documented reference hardware:

- No normal request reads and parses the complete config file.
- No normal request performs an individual telemetry file open/write/close cycle.
- Edge-added p95 latency for a simple cache hit remains within a documented small budget.
- Memory use remains bounded during collector outage.
- Telemetry loss is visible and quantified.
- Configuration reload does not cause broad request failure.

### Acceptance criteria

- Last-known-good configuration survives malformed snapshots.
- Active configuration version is visible.
- Queue sizes are bounded.
- Collector outage cannot fill disk indefinitely.
- Attack traffic cannot create unlimited unique events.
- Load evidence shows stable memory and improved throughput.

### Validation

#### Tests

- Snapshot schema.
- Atomic replacement.
- Malformed snapshot.
- Missing snapshot.
- Last-known-good behavior.
- Reload retry.
- Batch boundaries.
- Queue overflow.
- Flush retry.
- Event aggregation.
- Redaction.
- Queue corruption recovery.

#### Smoke

- Edge health reports active config version and age.
- Telemetry queue health is visible.
- Config reload succeeds.

#### End to end

- Change config in the control plane.
- Agent pulls and applies it.
- Workers activate it.
- Traffic behavior changes.
- Metrics and events batch to the agent and reach the core.

#### Stress and failure

- Sustained request load.
- Collector outage.
- Control-plane outage.
- Malformed snapshot.
- Slow disk.
- Disk full.
- Worker restart.
- Queue overflow.
- Event flood.
- Multi-domain configuration growth.

#### Documentation

- Edge config lifecycle.
- Last-known-good behavior.
- Telemetry queue design.
- Capacity tuning.
- Monitoring.
- Failure runbooks.
- Rollout and rollback.

---

## Phase 4 — Real challenge and clearance system

### Objective

Make WAF, bot, and rate-limit `challenge` actions perform a real challenge and clearance workflow instead of acting as a renamed block.

### Challenge model

The first implementation may use a self-hosted JavaScript proof-of-work challenge. It must be described as a friction and abuse-cost mechanism, not proof that a visitor is human.

The architecture must permit later providers or challenge types without replacing the policy engine.

### Scope

#### Edge challenge flow

1. An eligible browser navigation receives an edge-generated HTML challenge page.
2. The page receives a signed short-lived challenge token.
3. JavaScript computes a configurable proof.
4. JavaScript submits proof to an edge-only verification endpoint.
5. Successful verification returns a signed clearance cookie.
6. The browser returns to the original safe same-host relative URL.
7. Later eligible challenge rules accept valid clearance.
8. Explicit blocks and administrative denies still win.

#### Token contents and binding

Cryptographically bind:

- Challenge ID.
- Domain ID.
- Requested host.
- Original normalized relative path and query.
- Issue time.
- Expiry.
- Difficulty.
- Config version.
- Random nonce.
- Signing key ID.
- Optional user-agent hash.
- Optional privacy-preserving IP-prefix binding.

Requirements:

- HMAC-SHA-256 or a documented stronger supported primitive.
- Constant-time verification.
- Strict length limits.
- Active and previous keys for rotation.
- Protected secret source.
- Replay tracking with bounded storage.
- Wrong-host rejection.
- Expiry enforcement.
- Safe URL normalization.
- No open redirects.
- No customer-origin access for challenge endpoints.

#### Clearance cookie

Include:

- Domain or site binding.
- Issue and expiry timestamps.
- Signing key ID.
- Policy or config version where needed.

Attributes:

- `Secure`
- `HttpOnly`
- `SameSite=Lax`
- Configurable safe name.
- Configurable bounded lifetime.

#### Browser response

For eligible browser `GET` or `HEAD` navigation:

- Complete HTML page.
- No external JavaScript dependency.
- Restrictive Content-Security-Policy.
- `Cache-Control: no-store`.
- `X-Robots-Tag: noindex, nofollow`.
- Accessible progress and errors.
- Safe handling when JavaScript is unavailable.
- Request ID for support.

#### API and non-idempotent behavior

For APIs, uploads, and unsafe methods:

- Structured JSON.
- Stable error code.
- Challenge URL where appropriate.
- Expiry and retry guidance.
- Never automatically replay request bodies.
- Do not unexpectedly return HTML to JSON clients.

Suggested edge-only endpoints:

```text
/.well-known/cdnlite/challenge
/.well-known/cdnlite/challenge/verify
```

#### Configuration

Add validated per-domain configuration for:

- Enabled state.
- Challenge provider/type.
- Difficulty.
- Token lifetime.
- Clearance lifetime.
- Verification failure limit.
- Exempt paths.
- Exempt content types.
- User-agent binding.
- IP-prefix binding.
- Cookie name.
- Failure action.
- Active signing key metadata.
- Previous signing key metadata.
- Observation mode.

#### Events and metrics

Record bounded events for:

- Challenge issued.
- Challenge passed.
- Challenge failed.
- Challenge expired.
- Invalid signature.
- Wrong host.
- Replay detected.
- Clearance accepted.
- Clearance rejected.
- Challenge endpoint rate limited.

Do not log:

- Signing secrets.
- Full cookies.
- Full proof payloads.
- Unnecessary raw personal data.

### Acceptance criteria

- WAF, bot, and rate-limit challenge actions share one documented challenge engine.
- A valid proof produces working clearance.
- Invalid, altered, expired, replayed, and cross-domain tokens fail.
- Clearance cannot bypass explicit deny rules.
- Challenge endpoints never reach the customer origin.
- API clients receive JSON and are not replayed.
- Key rotation works with an overlap window.
- Events are visible in reporting.

### Validation

#### Tests

- Token generation and verification.
- Constant-time comparison helper.
- Expiry.
- Host and path binding.
- Replay.
- Cookie validation.
- Key rotation.
- Rule precedence.
- Browser/API negotiation.
- Size limits.
- Failure limits.

#### Smoke

- Challenge configuration reaches the snapshot.
- Edge reports challenge engine readiness.
- Challenge endpoint is edge-local.

#### End to end

- Browser-like request.
- Challenge HTML.
- Valid proof.
- Clearance cookie.
- Return to original path.
- Origin access.
- Invalid proof.
- Expired token.
- Altered host/path.
- Replay.
- Explicit deny override.
- JSON API behavior.

#### Security

- Open redirect.
- Cross-domain cookie.
- Token mutation.
- Oversized input.
- Replay flood.
- Endpoint abuse.
- Key compromise and rotation exercise.

#### Documentation

- Security model.
- Challenge semantics.
- API behavior.
- Dashboard controls.
- Tuning guidance.
- Privacy.
- Key rotation.
- Incident response.
- Rollout and rollback.

---

## Phase 5 — Adaptive overload protection and waiting room

### Objective

Protect overloaded or attacked origins by controlling origin admission while continuing to serve safe cached traffic.

### Important design rule

Do not use long `ngx.sleep()` calls as the main overload-control mechanism. Sleeping requests still hold connections and can worsen overload.

Use admission control and a waiting-room workflow.

### Scope

#### Overload signals

Track rolling windows per domain for:

- Incoming requests per second.
- Origin-bound requests per second.
- Active origin requests.
- Origin connection failures.
- Origin timeout ratio.
- Origin 5xx ratio.
- Origin latency p50, p95, and p99.
- Cache hit ratio.
- Queue population.
- Admitted requests.
- Rejected requests.
- Edge resource pressure where safely measurable.

#### State machine

Implement:

- Disabled.
- Monitoring.
- Healthy.
- Entering overload.
- Overloaded.
- Recovering.
- Manual emergency mode.

Controls:

- Consecutive unhealthy windows before activation.
- Consecutive healthy windows before recovery.
- Minimum state duration.
- Recovery ramp.
- Manual activation and deactivation.
- Manual override expiry.
- Audited state transitions.
- Reason codes.
- Protection against flapping.

#### Admission control

During overload:

- Continue serving valid safe cache hits.
- Gate cache misses and uncached origin requests.
- Admit a configurable number of origin-bound requests.
- Prioritize already admitted sessions.
- Support controlled health-check, administrator, and trusted-client exemptions.
- Use signed short-lived queue tickets.
- Use signed admission tokens or cookies.
- Limit waiting population.
- Limit per-client outstanding tickets.
- Use polling bounds and randomized jitter.
- Define queue overflow behavior.
- Bound all local queue state.
- Expose an interface for a future shared coordinator.
- Clearly document that the initial local-edge queue is not globally fair across all edge nodes.

#### Waiting page

For browser navigation:

- Edge-generated page.
- Heavy-traffic explanation.
- Estimated countdown.
- Jittered polling.
- Redirect only after admission.
- Safe same-host return URL.
- `Cache-Control: no-store`.
- `X-Robots-Tag: noindex, nofollow`.
- Restrictive CSP.
- Accessible status and errors.
- Request ID.

Suggested edge-only endpoints:

```text
/.well-known/cdnlite/queue
/.well-known/cdnlite/queue/status
```

#### API behavior

For APIs and unsafe methods:

- `429` or `503`.
- Stable error code.
- `Retry-After`.
- Request ID.
- Optional estimated wait.
- No automatic replay.

#### Dashboard

Add:

- Enabled state.
- Automatic/manual mode.
- Current state.
- Current reason.
- State age.
- RPS threshold.
- Active-origin threshold.
- Origin latency threshold.
- Origin error threshold.
- Admission rate.
- Queue limit.
- Hysteresis settings.
- Recovery ramp.
- Waiting-page title and message.
- Emergency activation.
- Live waiting, admitted, rejected, cache-served, and origin-bound counters.
- Audit confirmation for dangerous controls.

### Acceptance criteria

- Safe cached traffic remains available during origin overload.
- Origin-bound traffic stays at or below the configured admission budget.
- Queue state remains bounded.
- Recovery does not flap.
- Unsafe requests are not placed into browser replay loops.
- One overloaded domain does not degrade unrelated domains.
- Queue endpoints never reach customer origins.
- Invalid and altered tickets fail safely.
- Manual emergency actions are audited.

### Validation

#### Tests

- State transitions.
- Hysteresis.
- Recovery ramp.
- Ticket signatures.
- Expiry.
- Queue limits.
- Per-client limits.
- Browser/API negotiation.
- Exemptions.
- Multi-domain isolation.
- Manual override expiry.

#### Smoke

- Manual activation.
- State visible in API and dashboard.
- Queue endpoints respond locally.

#### End to end

- Make origin unhealthy or slow.
- Enter overload.
- Verify cache hits continue.
- Verify cache misses wait.
- Verify admission rate.
- Verify recovery.
- Verify API response.
- Verify audit events.

#### Load and failure

- Sudden traffic spike.
- Slow origin.
- Origin 5xx.
- Origin timeout.
- Multiple domains.
- Large waiting population.
- Polling abuse.
- Edge restart.
- Coordinator outage in future shared mode.
- Memory and connection bounds.

#### Documentation

- Architecture.
- Operator controls.
- API/OpenAPI.
- Capacity planning.
- Waiting-page customization.
- Emergency runbook.
- Fairness limitations.
- Rollout and rollback.

---

## Phase 6 — Cache correctness foundation

### Objective

Make cache decisions standards-aware, predictable, testable, and safe before adding more advanced performance features.

### Scope

#### Cache eligibility

Define and test behavior for:

- Request methods.
- Response status codes.
- `Cache-Control`.
- `Expires`.
- `Age`.
- `Vary`.
- `Authorization`.
- `Cookie` and `Set-Cookie`.
- Private and no-store responses.
- Query strings.
- Request and response headers.
- Range requests.
- Conditional requests.
- Redirects.
- Error responses.
- Large objects.
- Streaming responses.
- Request bodies.
- WebSockets and upgrades.

Provide safe defaults and explicit overrides.

#### Cache key

Build a normalized, documented cache key that can include:

- Scheme when required.
- Normalized host.
- Normalized path.
- Query string policy.
- Selected headers.
- Device class only when explicitly enabled.
- Country or region only when explicitly enabled.
- Language only when explicitly enabled.
- Cache-rule version.
- Domain ID.

Requirements:

- Prevent host confusion.
- Prevent cache poisoning.
- Prevent unsafe unbounded variation.
- Make each added dimension visible to operators.
- Report a cache-key debug representation without leaking secrets.

#### Revalidation and freshness

Implement or validate:

- Freshness lifetime.
- Conditional revalidation with ETag and Last-Modified.
- `stale-while-revalidate`.
- `stale-if-error`.
- Background update.
- Request collapsing or cache locking.
- Lock timeout and fallback.
- Negative caching with safe defaults.
- Origin error caching only when configured.
- Age propagation.
- Consistent edge cache-status headers.

#### Bypass and private content safety

Support:

- Path bypass.
- Method bypass.
- Header bypass.
- Cookie bypass.
- Authorization bypass.
- Origin response bypass.
- Explicit no-cache rule.
- Debug reason.

Never cache personalized or authenticated responses by default.

#### Cache status and debug

Expose a safe response header such as:

```text
X-CDNLite-Cache: HIT | MISS | BYPASS | STALE | REVALIDATED | EXPIRED
```

Optionally expose:

- Cache rule ID.
- Bypass reason.
- Edge ID.
- Age.
- Request ID.

Debug information must be configurable and must not reveal secrets.

### Acceptance criteria

- Personalized responses are not cached by default.
- Cache key normalization prevents host and query confusion.
- Concurrent misses can be collapsed.
- Stale behavior is explicit and bounded.
- Cache-status reporting matches actual behavior.
- Cache rules have deterministic precedence.
- Cache correctness has executable HTTP conformance tests.

### Validation

#### Tests

- Cache-Control matrix.
- Cookies and authorization.
- Vary behavior.
- Query normalization.
- Conditional requests.
- Range requests.
- Status-code policy.
- Stale behavior.
- Cache lock.
- Rule precedence.
- Poisoning attempts.
- Large-object limits.

#### Smoke

- Hit, miss, bypass, stale, and revalidation headers.
- Cache rule snapshot.
- Safe default behavior.

#### End to end

- Origin response.
- First miss.
- Second hit.
- Expiry.
- Revalidation.
- Stale on origin failure.
- Bypass on cookie/auth.
- Multi-domain cache isolation.
- Rule update propagation.

#### Performance

- Hit throughput.
- Miss throughput.
- Cache lock under concurrency.
- Disk use.
- Large object behavior.
- Cache eviction.

#### Documentation

- Cache model.
- Header behavior.
- Cache key.
- Rule precedence.
- Safe recipes.
- Debugging.
- Purge interactions.
- Rollout and rollback.

---

## Phase 7 — Origin routing, resilience, and shielding

### Objective

Make origin selection and failure behavior predictable under healthy, degraded, and failed conditions.

### Scope

#### Origin pools

Support:

- Primary and backup origins.
- Weighted load balancing.
- Least-connections or another documented algorithm where practical.
- Consistent hashing for selected use cases.
- Per-origin enabled state.
- Origin groups.
- Per-domain pool assignment.
- Explicit SNI.
- Host-header override.
- TLS verification.
- Custom CA bundle.
- IPv4 and IPv6.
- Connection and response timeouts.
- Maximum response size where applicable.

#### Health checks

Add:

- HTTP, HTTPS, and TCP checks where practical.
- Configurable path, method, expected status, and optional body match.
- Check interval.
- Timeout.
- Healthy and unhealthy thresholds.
- Jitter.
- Per-origin state and reason.
- Last success and failure.
- Latency history.
- Manual disable and drain.
- Health-check source identification.
- Protection from synchronized health-check storms.

#### Passive health and circuit breaking

Track:

- Connection failures.
- Timeouts.
- 5xx.
- Latency.
- Active requests.

Implement:

- Bounded retries for idempotent requests.
- No unsafe automatic retries for non-idempotent requests by default.
- Circuit breaker.
- Half-open recovery.
- Per-origin concurrency limit.
- Outlier ejection where multiple origins exist.
- Backoff and retry budget.
- Clear precedence between active and passive health.

#### Origin shielding

Add optional shield behavior:

- Designated shield edge or layer.
- Cache fill consolidation.
- Shield health and failover.
- Loop prevention.
- Shield bypass.
- Visibility into edge hit, shield hit, and origin hit.
- Clear topology limits for private deployments.

#### Origin authentication

Support:

- Custom origin headers with secret handling.
- mTLS as a future or advanced option.
- Signed origin request option where justified.
- Secret redaction.
- Rotation without broad outage.

### Acceptance criteria

- Origin health state is deterministic and observable.
- Safe idempotent retry behavior is bounded.
- Non-idempotent traffic is not duplicated by default.
- Circuit breaking protects failing origins.
- Backup routing recovers cleanly.
- Shield loops are impossible.
- Origin secrets are not exposed in snapshots intended for untrusted readers, logs, or dashboard bundles.

### Validation

#### Tests

- Health transitions.
- Weighted selection.
- Retry eligibility.
- Retry budget.
- Circuit breaker.
- Half-open recovery.
- Host and SNI behavior.
- TLS verification.
- Secret redaction.
- Shield loop prevention.

#### Smoke

- Primary origin healthy.
- Backup origin visible.
- Health API reports current state.

#### End to end

- Primary success.
- Primary failure.
- Backup failover.
- Primary recovery.
- Timeout.
- TLS/SNI origin.
- Weighted pool.
- Shield hit and miss.
- Config update and drain.

#### Failure and performance

- Slow origin.
- Connection refusal.
- Partial failures.
- Retry storm prevention.
- Large response.
- Origin pool churn.
- Health-check scaling.

#### Documentation

- Origin model.
- Load balancing.
- Health checks.
- Retry semantics.
- Circuit breaker.
- Shield topology.
- Origin TLS.
- Incident runbooks.
- Rollout and rollback.

---

# P1 — Dependable production CDN capabilities

## Phase 8 — Purge and invalidation platform

### Objective

Provide fast, safe, observable, and scalable cache invalidation.

### Scope

Support:

- Purge exact URL.
- Purge multiple URLs.
- Purge by host.
- Purge by path prefix.
- Purge by cache tag or surrogate key.
- Purge all for a domain.
- Optional soft purge.
- Optional purge by rule version.
- Purge status and progress.
- Idempotency key.
- Request limits.
- Authorization and audit.
- Per-domain concurrency control.
- Agent fan-out or edge polling semantics.
- Partial edge failure reporting.
- Retry and expiry.
- Offline-edge handling.
- Purge tombstone or version strategy to prevent stale reappearance.

Define cache-tag limits:

- Number per response.
- Tag length.
- Character set.
- Header size.
- Stored index size.
- Purge batch size.

### Performance targets

On the documented reference fleet:

- Exact URL purge acknowledged quickly.
- Purge propagation target and timeout are documented.
- Large purge requests are asynchronous.
- One purge cannot create unlimited work.
- Offline and failed edges are visible.

### Acceptance criteria

- Purge API semantics are idempotent.
- Multi-edge result is visible.
- Partial failure is not reported as full success.
- Purged content does not reappear through stale local state.
- Purge by tag is bounded.
- Purge permissions and audit records are complete.

### Validation

#### Tests

- URL normalization.
- Tag validation.
- Idempotency.
- Partial failure.
- Retry.
- Offline edge.
- Soft purge.
- Purge-all authorization.
- Tombstone/version behavior.

#### Smoke

- Exact purge.
- Status API.
- Audit event.

#### End to end

- Fill cache.
- Purge.
- Confirm miss.
- Refill.
- Multi-edge propagation.
- Tag purge.
- Offline edge reconnect.

#### Stress

- Large URL batch.
- Large tag batch.
- Concurrent purges.
- Repeated idempotency key.
- Edge outage.

#### Documentation

- Purge API.
- Dashboard workflow.
- Tag integration.
- Limits.
- Troubleshooting.
- Runbook.
- Rollout and rollback.

---

## Phase 9 — Edge protocol and delivery performance

### Objective

Improve client and origin delivery efficiency without weakening correctness or observability.

### Scope

#### Client protocols

- Production-grade HTTP/1.1 behavior.
- HTTP/2 support and tuning.
- Optional HTTP/3 and QUIC after supported runtime and operational testing.
- ALPN configuration.
- Connection and stream limits.
- Header size limits.
- Request timeout policy.
- Slow-client protection.
- WebSocket and upgrade pass-through.
- Streaming response support.
- Range request support.

#### TLS performance

- TLS 1.2 and TLS 1.3 policy.
- Secure cipher defaults.
- Session tickets or session cache.
- Ticket-key rotation.
- OCSP stapling where supported.
- Certificate chain correctness.
- SNI selection performance.
- Handshake metrics.
- Optional mTLS on administrative or origin boundaries.

#### Compression

- Gzip.
- Brotli where supported.
- Content-type allowlist.
- Minimum and maximum size.
- Compression-level defaults.
- Avoid double compression.
- Respect `no-transform`.
- Correct `Vary: Accept-Encoding`.
- Compression bomb and resource controls.
- Precompressed asset support.

#### Connection reuse

- Client keepalive.
- Origin keepalive pools.
- DNS resolver caching.
- Resolver failure behavior.
- Origin connection limits.
- Idle timeout.
- Reuse metrics.

#### Delivery hints

Later within this phase, consider:

- Early Hints only after correctness testing.
- Preload header preservation.
- Client hints with strict cache-key control.
- IPv6 listener and dual-stack testing.

### Acceptance criteria

- HTTP/2 is covered by runtime tests.
- Optional HTTP/3 is not marked stable until cross-client and failure tests pass.
- Compression changes cache keys and headers correctly.
- TLS session behavior and key rotation are documented.
- Connection reuse improves measured origin connection efficiency.
- Protocol limits prevent obvious resource exhaustion.

### Validation

#### Tests

- Protocol negotiation.
- Header limits.
- Compression eligibility.
- Vary behavior.
- Range requests.
- WebSockets.
- Streaming.
- TLS versions.
- Session resumption.
- Ticket rotation.

#### Smoke

- HTTP/1.1 and HTTP/2 requests.
- Gzip or Brotli response.
- TLS certificate and ALPN visibility.

#### End to end

- Browser-compatible HTTP/2.
- Optional HTTP/3 client.
- Origin keepalive.
- WebSocket pass-through.
- Streaming.
- Range request.
- Compression through cache.

#### Performance

- Requests per second.
- Connections per second.
- Handshake rate.
- Session resumption.
- Origin connection reuse.
- CPU per compressed byte.
- HTTP/2 concurrent streams.
- Optional HTTP/3 loss behavior.

#### Documentation

- Protocol support matrix.
- TLS policy.
- Compression.
- WebSockets and streaming.
- Tuning.
- Compatibility.
- Rollout and rollback.

---

## Phase 10 — DNS and GeoDNS reliability

### Objective

Make domain publication deterministic, observable, idempotent, and safe under failure and fleet changes.

### Scope

#### Desired-state reconciliation

- One canonical desired-state builder.
- Serialized reconcile runs.
- Bounded retries with jitter.
- Invalid-request fast failure.
- Real PowerDNS write verification.
- Ownership ledger for stale-record cleanup.
- Safe deletion.
- Per-domain and global sync.
- Diff preview.
- Drift detection.
- Last success and error.
- Reconciliation generation.
- Manual force sync.
- Safe concurrent mutation handling.

#### Record contract

Select and document one canonical contract for:

- Proxied apex.
- Proxied subdomain.
- DNS-only apex.
- DNS-only subdomain.
- IPv4.
- IPv6.
- CAA.
- TXT.
- MX.
- SRV where supported.
- Wildcards.
- DNSSEC interaction.

Align:

- Implementation.
- Tests.
- Contributor guidance.
- Examples.
- Architecture.
- User documentation.

#### GeoDNS and health-aware routing

- Shared healthy edge pool.
- Region and country policy.
- Fallback hierarchy.
- Edge health freshness.
- Minimum healthy edge count.
- IPv4 and IPv6 answers.
- TTL strategy.
- Maintenance and drain.
- Stable behavior during control-plane outage.
- Avoid rewriting every customer zone for one edge health change.
- MMDB update and validation.
- Resolver and ECS behavior documentation where applicable.

#### Delegation and verification

- Nameserver verification.
- Expected, observed, matched, and missing sets.
- Scheduled revalidation.
- Forced verification with reason and audit.
- Clear activation/deactivation behavior.
- Propagation guidance.
- DNS doctor workflow.

### Acceptance criteria

- Desired state converges after retry and restart.
- Record behavior has one canonical documented contract.
- Edge health changes update shared routing without unnecessary customer-zone rewrites.
- DNS failures are visible and actionable.
- Stale records are safely removed.
- DNSSEC behavior is documented.
- Real authoritative answers are validated.

### Validation

#### Tests

- Desired-state projection.
- Idempotency.
- Advisory lock or sync guard.
- Retry classification.
- Stale cleanup.
- Record normalization.
- Health pool.
- Geo fallback.
- Delegation verification.

#### Smoke

- PowerDNS API health.
- DNS sync state.
- Authoritative record presence.

#### End to end

- Real zone creation.
- Proxied and DNS-only records.
- Apex and subdomain.
- Health-driven answer change.
- Edge pool update.
- Record deletion.
- Delegation lifecycle.
- Core restart.

#### Stress and failure

- Large domain and record count.
- Edge churn.
- PowerDNS outage.
- API rate limit.
- Concurrent mutations.
- Reconciler restart.
- MMDB failure.
- Resolver failure.

#### Documentation

- DNS architecture.
- Record contract.
- GeoDNS.
- Delegation.
- DNSSEC.
- Troubleshooting.
- Capacity.
- Failure runbooks.
- Rollout and rollback.

---

## Phase 11 — TLS and certificate lifecycle

### Objective

Provide reliable and observable certificate issuance, renewal, activation, storage, and expiry handling.

### Scope

#### Managed certificates

- ACME DNS-01.
- Apex and wildcard requests.
- Account lifecycle.
- Challenge creation and cleanup.
- Bounded retries.
- Rate-limit-aware backoff.
- Job ownership and locking.
- Stale-job recovery.
- Renewal scheduling.
- Renewal window.
- Activation after validation.
- Chain storage.
- Certificate and key matching.
- Expiry monitoring.
- Failure reason and operator action.
- Staging and production ACME environments.

#### Manual certificates

- Certificate, chain, and key validation.
- Hostname coverage.
- Expiry warning.
- Replacement.
- Safe rollback.
- Secret storage.
- Redaction.
- Audit.

#### Edge distribution

- Versioned certificate bundle.
- Atomic activation.
- Last-known-good certificate.
- SNI lookup.
- Wildcard precedence.
- No wrong-certificate fallback.
- Propagation status.
- Edge apply errors.
- Revocation or emergency replacement workflow.

#### TLS policy

Per domain or policy:

- Minimum version.
- HTTP redirect.
- HSTS with safe warnings.
- Cipher profile.
- TLS 1.3.
- OCSP behavior.
- Client certificate requirement where later supported.

### Acceptance criteria

- Managed certificate jobs recover after interruption.
- DNS challenge records are cleaned safely.
- Edge activation is atomic.
- Invalid new material does not replace a healthy certificate.
- Expiry and renewal failures are visible before outage.
- Certificate secrets never enter logs, browser assets, or ordinary API responses.
- Emergency replacement and rollback are documented.

### Validation

#### Tests

- Job state.
- Locking.
- Retry.
- Stale recovery.
- PEM validation.
- Key match.
- Hostname match.
- Wildcard precedence.
- Atomic activation.
- Redaction.

#### Smoke

- Certificate status API.
- Scheduler health.
- Edge bundle state.

#### End to end

- ACME staging issuance.
- DNS challenge.
- Certificate activation.
- TLS request.
- Renewal.
- Failure and recovery.
- Manual import.
- Rollback.
- Multi-edge propagation.

#### Failure

- ACME outage.
- PowerDNS outage.
- Invalid certificate.
- Wrong key.
- Edge offline.
- Scheduler restart.
- Expired staging certificate.

#### Documentation

- Managed SSL.
- Manual import.
- TLS policy.
- Secret storage.
- Renewal operations.
- Troubleshooting.
- Incident runbooks.
- Rollout and rollback.

---

## Phase 12 — WAF, rate limiting, API protection, and abuse defense

### Objective

Provide predictable, explainable, bounded, and testable application protection.

### Scope

#### Rules engine

Define deterministic evaluation for:

- Host.
- Path.
- Method.
- Query.
- Header.
- Cookie.
- IP and CIDR.
- Country.
- User agent.
- Content type.
- Body inspection only with explicit size and type limits.
- Bot category.
- API route.
- Request rate.
- Origin state.

Actions:

- Allow.
- Log.
- Block.
- Challenge.
- Rate limit.
- Redirect only where safe and explicitly supported.
- Header mutation only where safe.
- Overload admission handoff.
- Observation mode.

Define:

- Rule priority.
- First-match versus continue semantics.
- Administrative deny precedence.
- Allowlist precedence.
- Challenge-clearance precedence.
- Cache interaction.
- Per-rule reason and ID.
- Versioning and rollback.

#### WAF

- Managed starter rules.
- Common injection and traversal coverage.
- Protocol anomaly checks.
- Request smuggling defense aligned with OpenResty behavior.
- Header and body size limits.
- Body parsing limits.
- File-upload handling.
- False-positive workflow.
- Rule exclusion by path/parameter.
- Observation and enforcement modes.
- Versioned managed rule sets.
- Upgrade notes for rule changes.

Do not claim full OWASP or enterprise WAF coverage without mapped executable tests.

#### Rate limiting

Support keys such as:

- IP.
- Authenticated identity header where trusted.
- API key hash.
- Path or route.
- Domain.
- Composite key with strict limits.

Algorithms:

- Token bucket or leaky bucket with documented semantics.
- Burst.
- Sustained rate.
- Penalty period.
- Local edge coordination initially.
- Future shared coordinator interface.
- Safe IPv6 prefix handling.
- Trusted proxy chain configuration.
- Exemptions.
- Response headers.
- `Retry-After`.
- Fail-open or fail-closed setting with explicit warnings.

#### API protection

- API path discovery with privacy limits.
- Per-route policy.
- JSON content-type enforcement.
- Method allowlist.
- Request size.
- Schema validation as an optional future step.
- Authentication-presence checks without logging secrets.
- Credential-stuffing presets.
- Login and password-reset protection.
- Enumeration protection.
- GraphQL considerations where later supported.
- Machine-readable errors.

#### IP and geo access

- IPv4 and IPv6 CIDR.
- Allow, block, log.
- Country policy.
- Trusted proxy configuration.
- Correct client-IP extraction.
- Spoofing protection.
- Audit and expiry.
- Emergency block.

#### Abuse and L7 DDoS foundations

- Connection and request-rate limits.
- Slow-client limits.
- Header and body limits.
- Event sampling.
- Cache-first protection.
- Overload integration.
- Per-domain isolation.
- Emergency protection profile.
- Clear statement that network-layer DDoS protection still depends on upstream network capacity and providers.

### Acceptance criteria

- Every selectable action performs the documented runtime behavior.
- Rule precedence is deterministic.
- Challenge integrates with Phase 4.
- Overload integrates with Phase 5.
- Rate-limit state is bounded.
- Client IP cannot be trivially spoofed through untrusted headers.
- Event production remains bounded during attacks.
- Stable rule errors include rule ID and request ID without leaking sensitive internals.

### Validation

#### Tests

- Matcher matrix.
- Rule precedence.
- IPv4 and IPv6.
- Trusted proxy.
- Rate algorithm.
- Burst.
- Retry-After.
- Exemptions.
- Body limits.
- Managed rules.
- False-positive exclusions.
- API content types.
- Login preset.

#### Smoke

- WAF block.
- Rate limit.
- IP block.
- Observation event.
- Rule metadata in snapshot.

#### End to end

- Malicious request.
- Security event.
- Dashboard visibility.
- Allowlist.
- Challenge.
- Rate limit.
- API JSON response.
- Rule update and rollback.
- Multi-domain isolation.

#### Security and load

- Header abuse.
- Oversized request.
- Slow request.
- Event flood.
- High-cardinality keys.
- IPv6 key growth.
- Trusted-proxy spoofing.
- Rule bypass attempts.

#### Documentation

- Rules language.
- Precedence.
- Managed presets.
- Rate semantics.
- API protection.
- IP extraction.
- Limits.
- False positives.
- Incident runbooks.
- Rollout and rollback.

---

## Phase 13 — Observability, analytics operations, and alerting

### Objective

Provide operator-grade health, metrics, logs, events, alerts, and diagnostic correlation.

### Scope

#### Metrics

Add Prometheus-compatible metrics for:

- Core API.
- Database pool and queries.
- Background jobs.
- Rollups.
- Edge requests.
- Cache outcomes.
- Origin latency and errors.
- WAF and rate limits.
- Challenge.
- Waiting room.
- Config version and age.
- Telemetry queue.
- Edge heartbeat.
- DNS reconciliation.
- GeoDNS pool.
- SSL jobs and expiry.
- Purge propagation.
- Dashboard API latency where measurable.

Avoid uncontrolled high-cardinality labels.

#### Dashboards

Provide Grafana examples for:

- Platform overview.
- Edge fleet.
- Cache performance.
- Origin performance.
- DNS and GeoDNS.
- TLS and certificate lifecycle.
- Security.
- Challenge and waiting room.
- Analytics pipeline.
- Jobs and queues.
- Capacity and saturation.

#### Logs and correlation

- Structured JSON logging option.
- Request ID from edge through origin request and telemetry.
- Job ID.
- Config version.
- Edge ID.
- Domain ID.
- Rule ID.
- Purge ID.
- Redaction.
- Sampling.
- Retention guidance.
- Support bundle with secret filtering.

#### Events and exports

Provide bounded, authenticated, paginated export for:

- Audit events.
- Security events.
- Operational events.
- DNS sync events.
- SSL jobs.
- Purge results.
- Config apply results.

Support:

- Filters.
- Time range.
- Cursor pagination.
- Maximum page.
- CSV or JSONL where useful.
- SIEM-friendly schema.
- Webhook delivery as a later milestone with signing, retry, and dead-letter behavior.

#### Alerts and SLO examples

Define example alerts for:

- Edge offline.
- Config stale.
- Telemetry dropping.
- Origin errors.
- Cache hit collapse.
- DNS sync failure.
- Certificate expiry.
- Rollup delay.
- Queue overload.
- Database growth.
- Disk saturation.
- Job backlog.

Publish example service indicators and objectives without claiming they are universally appropriate.

### Acceptance criteria

- All enabled components expose useful health and metrics.
- Alert examples link to runbooks.
- Logs can correlate a request across major components.
- Export APIs are bounded and authorized.
- High-cardinality and personal-data risks are documented.
- Support bundles redact secrets.

### Validation

#### Tests

- Metric format.
- Label bounds.
- Redaction.
- Cursor pagination.
- Export authorization.
- Webhook signature when added.
- Support bundle filtering.

#### Smoke

- Prometheus scrape.
- Health endpoints.
- Grafana provisioning.
- Event export.

#### End to end

- Generate cache, origin, WAF, DNS, SSL, purge, challenge, and queue activity.
- Verify metrics, events, and dashboard panels.
- Follow one request ID across components.

#### Load

- Metrics cardinality.
- Large event export.
- Log volume.
- Alert rule evaluation.
- Support bundle size.

#### Documentation

- Metrics catalog.
- Dashboard guide.
- Logging schema.
- Event schema.
- Alert runbooks.
- Retention.
- Privacy.
- SIEM integration.

---

## Phase 14 — Dashboard, API, CLI, and onboarding quality

### Objective

Make common CDN operations fast, understandable, recoverable, and consistent across dashboard, API, and CLI.

### Scope

#### Guided workflows

Provide optional guided flows for:

- First domain.
- Delegation verification.
- First origin.
- First edge.
- First cache policy.
- First WAF policy.
- First rate limit.
- Managed TLS.
- First purge.
- First analytics view.
- Emergency overload activation.

#### Dashboard quality

- Progressive loading.
- Server pagination.
- Search.
- URL-addressable filters.
- Preserve data during refresh.
- Partial failure handling.
- Request cancellation.
- Error reason and suggested action.
- Confirm destructive operations.
- Permission-aware controls.
- Accessible keyboard and screen-reader behavior.
- Responsive layouts.
- Fast large-table behavior.
- Time-zone clarity.
- Consistent empty states.
- Simple and Advanced modes without hiding operational truth.
- No secret in browser build variables.

#### API quality

- Stable versioning policy.
- OpenAPI as part of CI.
- Consistent pagination.
- Consistent error envelope.
- Request ID.
- Idempotency for mutating operations where needed.
- Optimistic concurrency or version checks.
- Rate limits.
- Safe maximum body size.
- Deprecation process when compatibility becomes required.
- Generated examples.

#### CLI quality

- Machine-readable JSON.
- Human-readable output.
- Noninteractive mode.
- Exit codes.
- Confirmation controls.
- Config profile.
- Token source.
- Safe secret handling.
- Commands for:
  - Health.
  - Domains.
  - DNS.
  - Origins.
  - Cache.
  - Purge.
  - Security.
  - TLS.
  - Edges.
  - Analytics jobs.
  - Config status.
  - Backup and restore later.

#### Diagnostics

Add focused diagnostics for:

- Domain activation.
- DNS.
- PowerDNS.
- GeoDNS.
- Edge registration.
- Heartbeat.
- Config apply.
- Origin connection.
- Cache decision.
- Purge.
- WAF match.
- Rate limit.
- Challenge.
- Waiting room.
- TLS.
- Metrics ingestion.
- Rollup freshness.

### Acceptance criteria

- A new operator can route a test domain through an edge to an origin.
- Every failed onboarding step offers an actionable diagnostic.
- Dangerous mutations show a preview and confirmation.
- Critical workflows have browser-level end-to-end tests.
- API, dashboard, and CLI use aligned contracts.
- Large domain and event lists remain usable.

### Validation

#### Tests

- Vue components and state.
- Accessibility.
- Error mapping.
- Pagination.
- Cancellation.
- CLI exit codes.
- API error envelope.
- Idempotency.
- Concurrency version checks.

#### Smoke

- Dashboard routes.
- API documentation.
- CLI health.
- Critical controls present only when supported.

#### End to end

- Browser automation for first domain to live edge request.
- Origin failover.
- Cache and purge.
- WAF and challenge.
- TLS state.
- Analytics.
- Diagnostics.
- CLI equivalent workflow.

#### Performance

- Dashboard initial load.
- Route transitions.
- Large tables.
- API request count.
- Bundle size budget.
- Slow-network behavior.

#### Documentation

- Quickstart.
- CDN in a Minute.
- User guide.
- Admin guide.
- CLI reference.
- API examples.
- Screenshots.
- Troubleshooting.
- Rollout and rollback.

---


## Phase 15 — Full-platform stress, soak, scale, and recovery qualification

### Objective

Build one reproducible stress-qualification system that tests every major CDNLite capability under realistic concurrency, sustained load, bursts, dependency failure, and recovery.

The repository already has DNS stress qualification. Preserve it, document it, and make it one suite in a broader platform stress program rather than the only large-scale workload.

### Stress-testing principles

1. **A throughput number without correctness checks is not a pass.**
2. **A load run without post-load recovery checks is not a pass.**
3. **A test that can damage real data must require an explicit disposable-environment flag.**
4. **Every result must identify hardware, software versions, dataset, topology, and limits.**
5. **Targets are tied to a reference environment and are not universal performance claims.**
6. **Stress tools must generate bounded artifacts and clean up after themselves.**
7. **New stable features must add or update stress scenarios when they affect capacity or shared resources.**
8. **The test suite must detect regressions against an approved baseline, not only command failure.**
9. **Correctness, isolation, recovery, and resource bounds are equal to peak throughput.**
10. **The system must be tested beyond expected normal load to identify the failure shape and safe operating limit.**

### Test tiers

#### Tier A — Pull-request qualification

Fast and resource-bounded:

- Small dataset.
- Short burst.
- Basic concurrency.
- No long soak.
- No massive DNS dataset.
- Validates scripts, thresholds, reports, and recovery assertions.
- Runs on normal CI where practical.

Target duration should remain appropriate for pull-request feedback.

#### Tier B — Nightly or scheduled qualification

Medium reference workload:

- Larger data seed.
- Edge traffic load.
- API concurrency.
- Analytics ingestion and reporting.
- DNS reconciliation.
- Security event load.
- Purge and config propagation.
- Dependency restart.
- Short soak.
- Regression comparison.

#### Tier C — Release qualification

Full disposable environment:

- Documented reference hardware.
- Full feature matrix.
- Sustained, burst, spike, soak, and failure profiles.
- Multi-edge and multi-domain topology.
- Database scale dataset.
- Post-stress smoke and end-to-end suite.
- Capacity and regression report.
- Required for a release that changes major request-path, database, queue, DNS, TLS, or fleet behavior.

#### Tier D — Manual destructive or extreme qualification

Explicit opt-in only:

- Very large DNS datasets.
- Very large telemetry datasets.
- Disk pressure.
- Network partitions.
- Database failover.
- Extended soak.
- Multi-region simulation.
- Resource exhaustion.
- Destructive reset.

Require an environment variable such as:

```bash
CDNLITE_ALLOW_DESTRUCTIVE_STRESS=1
```

The script must refuse to run destructive steps without an explicit disposable-environment declaration.

### Stress profiles

Every applicable suite should support consistent profiles.

#### Baseline

- Low steady traffic.
- Establish p50, p95, p99, CPU, memory, disk, network, and error baseline.
- Confirm data correctness.

#### Ramp

- Increase load in steps.
- Identify the first resource saturation point.
- Record queue growth and latency curve.

#### Burst

- Sudden short increase.
- Validate burst handling, cache locking, rate limits, and recovery.

#### Spike

- Immediate load far above normal.
- Validate safe rejection and bounded state.

#### Sustained load

- Hold expected high load.
- Validate stable latency, throughput, and queue depth.

#### Soak

- Hold moderate-to-high load for an extended period.
- Detect:
  - Memory leaks.
  - File-descriptor leaks.
  - Connection leaks.
  - Disk growth.
  - Index or table growth.
  - Queue drift.
  - Increasing aggregation lag.
  - Worker instability.
  - Log amplification.

#### Failure under load

Inject one failure at a time:

- Origin slow or unavailable.
- Core unavailable.
- PostgreSQL restart or slowdown.
- PowerDNS unavailable.
- Collector unavailable.
- Edge-agent restart.
- Worker restart.
- Invalid config snapshot.
- Disk pressure.
- DNS resolver failure.
- ACME staging failure.
- Optional identity-provider failure later.

#### Recovery

After load or failure:

- Error rate returns to baseline.
- Queues drain within a target.
- Rollup lag returns to target.
- Config converges.
- DNS converges.
- Origin health recovers.
- Edge remains registered.
- No duplicate or missing report totals beyond documented semantics.
- Smoke passes.
- End-to-end passes.
- No orphan jobs remain.
- No unbounded temporary files remain.

### Full capability stress matrix

Maintain a machine-readable matrix, for example:

```text
ci/stress/scenarios.yml
```

Each row should contain:

- Capability.
- Owner.
- Test script or scenario.
- Tier.
- Dataset.
- Load profile.
- Target throughput.
- Latency thresholds.
- Allowed error rate.
- Resource limits.
- Correctness assertions.
- Recovery assertions.
- Artifacts.
- Last successful evidence.
- Known limit.

Cover at minimum the following.

#### Edge request handling

- Cache hit.
- Cache miss.
- Cache bypass.
- Stale response.
- Revalidation.
- Concurrent cache fill.
- Large object.
- Range request.
- Compression.
- HTTP/2.
- WebSocket and streaming where supported.
- Multi-domain config lookup.
- Access logging and telemetry enabled.

Measure:

- Requests per second.
- p50/p95/p99 latency.
- Edge-added latency.
- Active connections.
- Worker CPU.
- Worker memory.
- File descriptors.
- Cache disk throughput.
- Error rate.

#### Origin behavior

- Healthy origin.
- Slow origin.
- Timeout.
- Connection refusal.
- 5xx.
- Primary/backup failover.
- Weighted pool.
- Circuit breaker.
- Retry budget.
- Recovery.
- Overload admission.
- Shielding when implemented.

Assert:

- Non-idempotent requests are not duplicated.
- Origin concurrency remains bounded.
- Failover time is within target.
- Recovery does not flap.

#### Cache correctness under concurrency

- Many clients request one uncached object.
- Many objects miss simultaneously.
- Purge during fill.
- Revalidation during load.
- Stale-if-error during origin failure.
- Cache eviction pressure.
- Tag purge under traffic.
- Cache-key isolation across domains.

Assert:

- No cache poisoning.
- No cross-domain object leakage.
- Collapsed forwarding works as documented.
- Purged content does not incorrectly reappear.

#### Security pipeline

- WAF allow/log/block.
- Managed rule set.
- IP and geo access.
- Rate limiting.
- High-cardinality attack keys.
- IPv6 clients.
- Bot classifications.
- Challenge issue and verify.
- Invalid challenge flood.
- Clearance.
- Waiting-room tickets and polling.
- Security-event production.

Assert:

- Explicit deny precedence.
- Bounded event volume.
- Bounded state memory.
- Stable machine-readable errors.
- Challenge and queue endpoints do not reach origins.

#### Control-plane API

- Authentication.
- Domain CRUD.
- DNS record CRUD.
- Origin and pool changes.
- Cache/security policy changes.
- Purge submission.
- Edge list and config status.
- Analytics queries.
- Event and audit pagination.
- Concurrent updates.
- Optimistic conflict behavior.

Measure:

- API latency.
- Database pool use.
- Error classification.
- Lock waits.
- Queue creation.
- Memory.

#### Dashboard

Use browser or browser-compatible performance scenarios for:

- Initial load with many domains.
- Large edge list.
- Large event list.
- Analytics filters.
- Rapid filter changes.
- Partial API failure.
- Slow network.
- Concurrent operators.

Measure:

- Initial content time.
- Interactive time.
- API request count.
- Cancelled obsolete requests.
- Memory growth.
- Long tasks.
- Error recovery.

#### Database and reporting

- Telemetry ingestion.
- Duplicate batch.
- Late event.
- Current-state update.
- Minute/hour/day rollups.
- Interactive report.
- Global report.
- Export.
- Backfill.
- Retention.
- Reconciliation.
- Concurrent control-plane writes.

Measure:

- Ingest rate.
- Query latency.
- Rows scanned.
- WAL.
- CPU.
- Memory.
- Disk.
- Pool saturation.
- Rollup lag.
- Reconciliation accuracy.

#### Edge-agent and configuration fleet

- Registration.
- Heartbeat.
- Config poll with no change.
- Config update.
- Large config.
- Many edges.
- Invalid snapshot.
- Metrics batch.
- Security-event batch.
- Collector outage.
- Queue recovery.
- Token rotation.

Assert:

- Last-known-good config remains active.
- Fleet converges.
- Queue size stays bounded.
- Dropped telemetry is reported.
- One failed edge does not block others.

#### DNS and GeoDNS

Preserve and expand the existing DNS stress suite.

Cover:

- Large domain count.
- Large record count.
- Multiple edges.
- Edge health flapping.
- Full reconciliation.
- Incremental reconciliation.
- Stale cleanup.
- PowerDNS outage.
- Core restart.
- Concurrent DNS mutations.
- Authoritative query load.
- Geo fallback.
- IPv4 and IPv6.

The current DNS stress script should remain available as a specialized destructive qualification and produce JSON and Markdown reports.

#### TLS and certificate jobs

Use ACME staging or controlled fakes for load-safe testing:

- Many queued certificate jobs.
- Renewal scheduling.
- Stale-job recovery.
- DNS challenge creation and cleanup.
- Edge certificate bundle apply.
- Invalid certificate.
- Edge offline.
- Scheduler restart.

Do not intentionally hit production ACME rate limits.

#### Purge

- Exact URL.
- Batch URL.
- Prefix.
- Tag.
- Domain-wide.
- Multiple edges.
- Offline edge.
- Concurrent purge.
- Repeated idempotency key.
- Purge during traffic.

Measure:

- Submission latency.
- Propagation.
- Partial failures.
- Retry.
- Edge queue use.

#### Jobs and schedulers

- DNS reconcile jobs.
- Origin health.
- SSL jobs.
- Analytics rollups.
- Backfills.
- Retention.
- Exports.
- Recommendations or other scheduled work.

Assert:

- No duplicate corruption with multiple workers.
- Backlog is visible.
- Recovery drains backlog.
- One job type cannot starve all others.

### Tooling and repository structure

Create a common stress framework rather than unrelated scripts.

Suggested structure:

```text
ci/
  stress-platform.sh
  stress-dns.sh
  stress/
    README.md
    scenarios.yml
    profiles/
      pr.env
      nightly.env
      release.env
      extreme.env
    lib/
      common.sh
      metrics.sh
      assertions.sh
      reports.sh
      recovery.sh
    edge/
    api/
    analytics/
    database/
    dns/
    tls/
    purge/
    security/
    agent/
    dashboard/
  reports/
    stress/
```

`ci/stress-platform.sh` should:

1. Validate the environment.
2. Refuse unsafe targets.
3. Capture versions and topology.
4. Seed data.
5. Establish baseline.
6. Run selected scenarios.
7. Inject selected failures.
8. Stop load.
9. Wait for bounded recovery.
10. Run correctness checks.
11. Run smoke.
12. Run applicable end-to-end suites.
13. Collect artifacts.
14. Compare with baseline.
15. Exit nonzero on threshold or correctness failure.
16. Clean up bounded temporary state.

Use one or more maintained load-generation tools selected by the project, plus shell and SQL assertions. Avoid making report parsing depend on a hosted service.

### Safety controls

Stress scripts must:

- Default to local/disposable endpoints.
- Reject known production hostnames or require explicit override.
- Require explicit destructive permission.
- Print the target topology and dataset before mutation.
- Cap generated data by default.
- Cap report and log size.
- Set test timeout.
- Clean up processes.
- Restore stopped services where possible.
- Keep secrets out of reports.
- Record that results are invalid if the environment was shared with unrelated workloads.
- Never run destructive resets through normal smoke or pull-request jobs.

### Metrics and artifact collection

Capture:

- Test configuration.
- Git commit.
- Image versions.
- Hardware and OS.
- Container limits.
- Dataset.
- Topology.
- Start and end time.
- Request count.
- Throughput.
- Latency distribution.
- Error classification.
- CPU.
- Memory.
- Disk.
- Network.
- File descriptors.
- Database metrics.
- Queue depths.
- Rollup lag.
- DNS convergence.
- Config convergence.
- Recovery time.
- Correctness checks.
- Smoke and e2e results.
- Logs around failures.

Produce:

- JSON report for automation.
- Markdown summary for reviewers.
- Time-series files or Prometheus snapshot where practical.
- Comparison against approved baseline.
- Clear PASS, FAIL, or INCONCLUSIVE result.

### Regression policy

Define thresholds per scenario.

Examples:

- p95 latency regression percentage.
- Throughput regression percentage.
- Memory growth.
- Error-rate increase.
- Recovery-time increase.
- Query rows-scanned increase.
- Rollup-lag increase.
- DNS convergence regression.
- Config propagation regression.
- Purge propagation regression.

A regression can be approved only with:

- Explanation.
- Capacity impact.
- Updated documented limit.
- Owner.
- Follow-up issue where needed.
- Roadmap and evidence update.

Do not silently replace the baseline after a regression.

### Initial qualification targets

Final targets must be tied to reference hardware. Initial gates should include:

- No unbounded memory, disk, queue, event, or connection growth.
- No cross-domain data or cache leakage.
- No loss of control-plane correctness under reporting load.
- Cache-hit error rate within the defined small threshold.
- API errors are classified rather than random connection failures where overload controls are expected.
- Origin admission limits hold under spike traffic.
- Telemetry queues recover after collector restoration.
- Analytics lag returns to target after burst ingestion.
- DNS and config converge after injected dependency recovery.
- All post-stress smoke checks pass.
- All applicable post-stress end-to-end workflows pass.
- No failed or stuck background jobs remain outside documented retry state.

### Deliverables

- Full capability stress matrix.
- Common stress runner.
- Reusable scenario libraries.
- PR, nightly, release, and extreme profiles.
- Database and telemetry dataset generators.
- Edge and origin load fixtures.
- Failure-injection controls.
- Recovery assertion library.
- JSON and Markdown report generation.
- Baseline comparison.
- CI scheduled workflow.
- Release qualification workflow.
- Stress operations and safety guide.
- Capacity and known-limits document.

### Acceptance criteria

- Every Stable major capability has a stress-matrix entry or a documented reason stress testing is not applicable.
- Existing DNS stress qualification remains functional and is included in the matrix.
- Release qualification runs all P0 and P1 request-path, data, fleet, DNS, TLS, security, and recovery scenarios applicable to the release.
- Stress runs validate correctness and post-load recovery.
- Stress tools refuse unsafe destructive execution by default.
- Reports are reproducible and include environment metadata.
- Approved baselines are versioned.
- CI detects defined regressions.
- The roadmap evidence for a release links to the stress report.
- A failed stress gate cannot be bypassed without a documented release decision.

### Validation of the stress framework

#### Unit and integration

- Scenario schema.
- Profile parsing.
- Threshold parsing.
- Safety target detection.
- Destructive-run guard.
- Metric parsing.
- Percentile calculation.
- Report generation.
- Baseline comparison.
- Cleanup traps.
- Failure injection.
- Recovery timeout.

#### Smoke

Run a minimal profile that:

- Seeds a small dataset.
- Sends traffic.
- Exercises one API mutation.
- Ingests telemetry.
- Executes one report.
- Produces JSON and Markdown.
- Stops load.
- Confirms recovery.
- Runs the normal smoke suite.

#### End to end

The stress runner itself must prove:

```text
seed
  -> load
  -> feature behavior
  -> metrics
  -> failure
  -> recovery
  -> correctness
  -> smoke
  -> e2e
  -> report
```

#### Self-test failure cases

Prove the framework fails when:

- Latency exceeds threshold.
- Error rate exceeds threshold.
- Memory exceeds limit.
- Queue does not drain.
- Rollup totals mismatch.
- DNS does not converge.
- Config remains stale.
- Smoke fails after load.
- Report cannot be written.
- Cleanup cannot restore required services.

#### Documentation

- Stress architecture.
- Tool installation.
- Profiles.
- Scenario authoring.
- Safety.
- Reference hardware.
- Baseline approval.
- Reading reports.
- Capacity interpretation.
- CI schedules.
- Release gate.
- Troubleshooting.
- Cleanup.
- Known limitations.

---

## Phase 16 — Secret, token, supply-chain, and release security

### Objective

Make credentials rotatable, reduce secret exposure, and make releases verifiable.

### Scope

#### Secret inventory and sources

Inventory:

- Admin bootstrap credentials.
- API tokens.
- Edge tokens.
- Database credentials.
- PowerDNS key.
- ACME credentials.
- TLS private keys.
- SSL encryption key.
- Challenge signing keys.
- Queue/admission signing keys.
- Webhook secrets.
- OIDC/SAML secrets later.

Support secret sources:

- Environment.
- Mounted files.
- External secret manager interface.
- Container/Kubernetes secret integration later.

#### Rotation

Implement rotation for:

- API tokens.
- Edge tokens.
- Challenge keys.
- Queue/admission keys.
- PowerDNS key.
- ACME credentials where possible.
- Encryption keys with a documented migration strategy.
- Session keys later.

Requirements:

- Active and previous values during bounded overlap.
- Audit events.
- Last-used visibility.
- Expiry.
- Revocation.
- Emergency rotation.
- No broad plaintext exposure.

#### Supply chain

- Dependency scanning.
- Container image scanning.
- Secret scanning.
- License reporting.
- SBOM.
- Pinned or controlled base images.
- Multi-architecture build verification.
- Signed release artifacts.
- Checksums.
- Provenance where supported.
- Release notes.
- Reproducibility guidance.

#### Security process

- Threat models.
- Security review checklist.
- Vulnerability disclosure.
- Severity and response process.
- Patch release process.
- Supported-version policy when project maturity requires it.
- Security regression tests.

### Acceptance criteria

- Supported credentials rotate without uncontrolled downtime.
- Revocation works after overlap.
- Secrets are masked in API, logs, events, support bundles, snapshots, and dashboard.
- Published releases have checksums, signatures, and SBOMs.
- CI blocks known critical secret leaks and defined severe vulnerabilities.
- Emergency procedures are documented and exercised.

### Validation

#### Tests

- Rotation overlap.
- Revocation.
- Last-used.
- Masking.
- Audit.
- Secret source fallback.
- Key migration.
- Authorization.

#### Smoke

- Rotate development edge token.
- Preserve edge health.
- Verify release artifacts in CI.

#### End to end

- Rotate core/edge credentials in a running disposable stack.
- Rotate challenge and queue keys.
- Verify previous-key overlap and final revocation.

#### Security

- Secret scan.
- Dependency scan.
- Image scan.
- Artifact signature verification.
- SBOM generation.
- Support bundle review.

#### Documentation

- Secret inventory.
- Secret sources.
- Rotation runbooks.
- Release verification.
- Vulnerability response.
- Emergency rollback.

---

# P2 — Enterprise management and resilience

## Phase 17 — Backup, restore, disaster recovery, and control-plane HA

### Objective

Turn backup, restore, and resilience guidance into tested operational capabilities.

### Scope

#### Backup

- PostgreSQL logical and/or physical backup guidance.
- Scheduled backup job examples.
- Backup manifest.
- Integrity validation.
- Encryption guidance.
- Retention.
- Off-site copy.
- Configuration inventory.
- Certificate and key inventory.
- Deployment manifest inventory.
- DNS desired-state export.
- Version compatibility metadata.

#### Restore

- Clean-environment restore.
- Point-in-time strategy where supported.
- Validation after restore.
- Rebuild derived aggregates when appropriate.
- Reconcile DNS after restore.
- Republish snapshots.
- Restore certificate activation.
- Restore audit and security history.
- Explicit handling of secrets not stored in database.

#### Disaster recovery

Runbooks for:

- Database loss.
- Core loss.
- Dashboard loss.
- Edge loss.
- DNS loss.
- Config corruption.
- Certificate data loss.
- Secret compromise.
- Failed upgrade.
- Region loss in future deployments.

Define target examples for:

- Recovery point objective.
- Recovery time objective.
- Backup frequency.
- Restore-test frequency.

#### Control-plane HA

- Multiple core instances.
- Stateless API behavior where practical.
- Shared database.
- Scheduler and worker leader election or safe duplicate execution.
- Job locking.
- Health and readiness.
- Load balancer guidance.
- Session handling.
- Safe maintenance.
- PostgreSQL HA guidance with explicit support boundaries.
- Edge operation during control-plane outage.
- DNS behavior during control-plane outage.
- Last-known-good config duration.

### Acceptance criteria

- A clean environment can be restored from documented artifacts.
- Restore drills verify management workflows and edge traffic.
- Duplicate workers do not corrupt jobs.
- Edge behavior during core outage is documented and tested.
- RPO and RTO expectations are explicit.
- HA documentation states supported and unsupported failure modes.

### Validation

#### Tests

- Backup manifest.
- Integrity checks.
- Job locking.
- Duplicate worker behavior.
- Restore validation.
- Derived-data rebuild.

#### Smoke

- Backup.
- Validate.
- Health of multi-core reference setup where provided.

#### End to end

- Create disposable state.
- Back up.
- Destroy.
- Restore.
- Start.
- Reconcile.
- Verify DNS, TLS, config, edge traffic, analytics, and audit.

#### Failure injection

- Core restart.
- Scheduler duplication.
- Database unavailable.
- Snapshot corruption.
- Partial restore.
- Failed upgrade.

#### Documentation

- Backup.
- Restore.
- DR.
- HA architecture.
- RPO/RTO.
- Upgrade.
- Rollback.
- Drill evidence template.

---

## Phase 18 — RBAC and scoped API keys

### Objective

Replace all-or-nothing administration with least-privilege access.

### Scope

Define permissions for:

- Domains.
- DNS.
- Origins.
- Cache.
- Purge.
- Security.
- TLS.
- Edges.
- Analytics.
- Events.
- Audit.
- Jobs.
- Settings.
- Users.
- Roles.
- Tokens.
- Backups.
- Emergency controls.

Provide:

- Built-in roles.
- Custom role foundation.
- Resource and domain scope.
- Read versus write separation.
- Emergency-admin role.
- Permission change audit.
- Session refresh after role change.
- Service-boundary enforcement.
- Permission-aware dashboard.
- Scoped API keys with:
  - Name
  - Owner
  - Scopes
  - Domain restrictions
  - Expiry
  - Last used
  - Rotation
  - Revocation
  - Optional source-network restriction
- CSRF protection for browser mutations where applicable.

### Acceptance criteria

- Authorization is enforced inside services, not only routes or UI.
- Unauthorized reads and writes fail consistently.
- API keys cannot exceed owner permissions.
- Key material is shown only at creation.
- Permission changes take effect safely.
- Emergency actions require explicit permission and audit.

### Validation

#### Tests

- Permission matrix.
- Resource scope.
- Escalation attempts.
- Expired and revoked keys.
- Owner permission reduction.
- UI permission state.
- CSRF.

#### Smoke

- Read-only user.
- Restricted key.
- Denied mutation.

#### End to end

- Multiple roles across domains, DNS, cache, security, TLS, analytics, and settings.
- Scoped key automation.
- Role change.
- Session refresh.
- Audit record.

#### Security

- Horizontal escalation.
- Vertical escalation.
- ID guessing.
- Scope confusion.
- Key leakage response.

#### Documentation

- Permission catalog.
- Built-in roles.
- Custom roles.
- API key lifecycle.
- Migration.
- Emergency access.
- Rollback.

---

## Phase 19 — OIDC, SAML, sessions, and enterprise identity

### Objective

Provide native external identity integration while preserving a monitored break-glass path.

### Scope

#### OIDC

- Authorization code flow.
- PKCE.
- State and nonce.
- Issuer discovery.
- JWKS rotation.
- Audience and issuer validation.
- Claim mapping.
- Group-to-role mapping.
- Just-in-time provisioning controls.
- Account linking policy.
- Logout.
- Reauthentication.
- Provider outage handling.

#### SAML

Only mark stable after maintainable interoperability testing:

- Metadata.
- Signed assertions.
- Audience and destination checks.
- Clock skew.
- Certificate rotation.
- Group mapping.
- Single logout where practical.
- Replay prevention.
- Error handling.

#### Sessions

- Secure cookies.
- Session expiry.
- Idle timeout.
- Absolute timeout.
- Rotation after authentication.
- Revocation.
- Concurrent-session policy.
- Step-up authentication for dangerous actions where practical.
- Break-glass local admin.
- Audit.

### Acceptance criteria

- At least one OIDC provider works end to end.
- SAML remains Experimental until interoperability tests pass.
- Provider outage does not remove documented emergency access.
- Mapping is deterministic and audited.
- Sessions respect revocation and expiry.
- Open redirect, login CSRF, and replay defenses pass.

### Validation

#### Tests

- State.
- Nonce.
- PKCE.
- JWKS rotation.
- Claim mapping.
- Logout.
- Session expiry.
- Provider errors.
- SAML assertion checks where supported.

#### Smoke

- Login through local test identity provider.
- Break-glass path.

#### End to end

- Login.
- Provision.
- Map role.
- Access.
- Denial.
- Role change.
- Session expiry.
- Logout.
- Provider outage.

#### Security

- Redirect attack.
- Replay.
- Claim injection.
- Account-link confusion.
- Session fixation.
- Stolen-session revocation.

#### Documentation

- Provider setup.
- Mapping examples.
- Session policy.
- Break-glass.
- Outage runbook.
- Migration and rollback.

---

## Phase 20 — Tenant isolation, quotas, and SIEM boundaries

### Objective

Introduce explicit ownership and prevent cross-tenant access or data leakage.

### Scope

#### Ownership model

Add tenant ownership to:

- Users.
- Domains.
- DNS records.
- Origins.
- Cache rules.
- Security rules.
- TLS assets.
- Edges where appropriate.
- API keys.
- Snapshots.
- Jobs.
- Events.
- Analytics.
- Audit.
- Backups.
- Settings.

#### Isolation

- Tenant-aware authorization.
- Tenant-aware database queries.
- Tenant-aware cache keys.
- Tenant-aware job scope.
- Tenant-aware exports.
- Tenant-aware support workflows.
- Snapshot isolation.
- Event and analytics isolation.
- Identifier-guessing defense.
- No silent administrator impersonation.

#### Quotas

Define optional quotas for:

- Domains.
- DNS records.
- Origins.
- Edges.
- Cache storage.
- Requests.
- Bandwidth.
- Purges.
- Rules.
- Events.
- Retention.
- API usage.

Quota enforcement must be explicit and fail with stable errors.

#### SIEM and privacy

- Tenant-specific export.
- Retention.
- Deletion.
- Data portability.
- Support-access audit.
- Personal-data minimization.
- IP handling policy.
- Regional-storage limitations documentation.

### Acceptance criteria

- Cross-tenant reads and writes fail across API, dashboard, workers, cache, jobs, and exports.
- Background jobs retain tenant scope.
- Query caches cannot leak data.
- Snapshots contain only authorized tenant/domain data.
- Quotas are deterministic and visible.
- Tenant deletion/export semantics are documented.

### Validation

#### Tests

- Tenant filters.
- Cache separation.
- Job scope.
- Export scope.
- Quota enforcement.
- Support access.
- Deletion.

#### Smoke

- Two tenants.
- Separate domain lists.
- Denied cross-access.

#### End to end

- Domain.
- Analytics.
- Events.
- Edge.
- Key.
- TLS.
- Purge.
- Audit.
- Export.
- Quota.

#### Security

- IDOR.
- Cache leakage.
- Cross-tenant job manipulation.
- Snapshot leakage.
- Export leakage.
- Support impersonation.

#### Documentation

- Tenancy model.
- Quotas.
- Support access.
- Privacy.
- Deletion/export.
- Migration.
- Rollback.

---

## Phase 21 — Policy as code and managed presets

### Objective

Make CDN, cache, routing, and security policy repeatable, reviewable, versioned, and safely promotable.

### Scope

#### Policy format

Create a versioned schema for:

- Domains or domain references.
- Origins and pools.
- Cache settings.
- Cache rules.
- Redirects.
- Headers.
- WAF.
- Rate limits.
- Bot policy.
- Challenge.
- Overload protection.
- TLS policy.
- DNS records where appropriate.
- Alerts.
- Retention.

Support:

- Validate.
- Format.
- Diff.
- Preview.
- Plan.
- Apply.
- Export.
- Rollback.
- Ownership metadata.
- Managed versus manual resource state.
- Environment variables or substitutions with strict controls.
- Secret references rather than embedded secrets.
- Schema migration.

#### Managed presets

Provide tested presets for:

- Static website.
- General website.
- WordPress.
- E-commerce baseline.
- API.
- Login protection.
- Download service.
- Media delivery.
- Emergency protection.
- Bot challenge.
- Origin overload.
- Private internal application.

Every preset label must match real runtime semantics.

#### GitOps workflow

- Pull-request review.
- Dry-run.
- Signed or authorized apply.
- Drift detection.
- Apply result.
- Rollback.
- Audit.
- CI examples.

### Acceptance criteria

- The same policy produces deterministic effective configuration.
- Preview does not mutate.
- Apply is auditable.
- Rollback restores the previous effective policy.
- Unsupported actions cannot be imported as Stable.
- Secret values cannot be embedded accidentally.
- Manual override and detach behavior are explicit.

### Validation

#### Tests

- Schema.
- Formatting.
- Diff.
- Determinism.
- Conflict.
- Ownership.
- Rollback.
- Drift.
- Secret rejection.
- Schema migration.

#### Smoke

- Validate and preview sample policy.
- Apply simple preset.

#### End to end

- Promote policy.
- Verify edge behavior.
- Change.
- Detect drift.
- Roll back.
- Verify restoration.

#### Security

- Malicious input.
- Oversized document.
- Unsafe redirect.
- Header injection.
- Secret inclusion.
- Unauthorized apply.

#### Documentation

- Schema.
- Examples.
- GitOps.
- Presets.
- Ownership.
- Drift.
- Migration.
- Rollback.

---

# P3 — Deployment ecosystem and advanced services

## Phase 22 — Kubernetes, Helm, Terraform, and fleet automation

### Objective

Provide repeatable deployment and fleet operations beyond the supported root Docker Compose topology.

### Scope

#### Helm and Kubernetes

- Core.
- Dashboard.
- Workers and schedulers.
- Edge.
- Edge agent.
- Optional DNS components.
- Health and readiness.
- Resource requests and limits.
- Pod disruption.
- Security contexts.
- Network policies.
- Persistent storage.
- Secret integration.
- Certificate integration.
- Horizontal scaling.
- Rolling update.
- Rollback.
- Backup integration.
- Versioned values schema.
- Local test environment.

#### Terraform

Provide examples for:

- Private network.
- Core hosts.
- Edge hosts.
- DNS.
- Firewall rules.
- Load balancer.
- Object storage for backups where used.
- Secret-manager references.
- Monitoring.
- Multi-region foundation.

Avoid placing production secrets directly in example state.

#### Edge fleet automation

- Enrollment token with expiry.
- Per-edge identity.
- Labels and region.
- Drain.
- Maintenance.
- Safe rolling config.
- Canary edge.
- Config rollout percentage.
- Automatic rollback on apply or health failure.
- Capacity view.
- Autoscaling examples based on safe signals.
- Fleet version and drift.

### Acceptance criteria

- A documented local Kubernetes install passes runtime workflows.
- Helm upgrade and rollback pass.
- Secrets are not stored in defaults.
- Compose remains supported.
- Edge canary and rolling rollout are observable.
- Autoscaling examples do not use unsafe or unbounded signals.

### Validation

#### Tests

- Helm lint.
- Helm template.
- Values schema.
- Terraform format and validate.
- Manifest security policies.
- Rollout state machine.

#### Smoke

- Install chart.
- Health and readiness.
- Edge registration.

#### End to end

- Domain to edge traffic on Kubernetes.
- DNS where included.
- Config rollout.
- Cache.
- Security.
- TLS.
- Analytics.

#### Resilience

- Pod restart.
- Rolling update.
- Failed rollout.
- Rollback.
- Persistent-state recovery.
- Edge drain.
- Core scaling.

#### Documentation

- Helm reference.
- Kubernetes architecture.
- Terraform examples.
- Security.
- Scaling.
- Upgrade.
- Rollback.
- Limitations.

---

## Phase 23 — Advanced CDN services

### Objective

Add higher-level delivery features only after the core cache, routing, security, and operations platform is dependable.

### Candidate capabilities

#### Signed delivery

- Signed URLs.
- Signed cookies.
- Expiry.
- Path scope.
- Key rotation.
- Optional IP-prefix binding.
- Hotlink protection.
- Private downloads.
- HLS/DASH segment compatibility.
- No origin-secret exposure.

#### Image optimization

- Resize.
- Format conversion.
- Quality.
- DPR.
- Safe dimension limits.
- Pixel-count limits.
- CPU and memory budgets.
- Cache key.
- Metadata stripping policy.
- Animated image limits.
- Source allowlist.
- Abuse protection.
- Optional separate worker service rather than expensive edge-process work.

#### Media delivery foundations

- Large object caching.
- Range requests.
- Cache slicing where justified.
- HLS/DASH cache policy examples.
- Origin shielding.
- Signed delivery.
- Purge tags.
- Storage capacity planning.

Do not claim full streaming platform features without specialized validation.

#### Prefetch and warming

- Explicit URL list.
- Bounded concurrency.
- Per-domain budgets.
- Idempotency.
- Progress.
- Cancel.
- Avoid origin overload.
- Warm through shield.
- Audit.

#### Edge includes and transformations

Only consider after a strict security design:

- Small response-header transforms.
- Safe redirects.
- Limited HTML include or fragment model.
- No arbitrary untrusted code in the first implementation.
- Strong CPU, memory, time, network, and output limits.

#### Edge functions

A future optional architecture may provide isolated functions using a sandboxed runtime, but must not run arbitrary tenant code inside the primary OpenResty request process.

Requirements before implementation:

- Threat model.
- Isolation boundary.
- CPU and wall-time limits.
- Memory limit.
- Network policy.
- Package policy.
- Logging and billing dimensions.
- Cold-start expectations.
- Deterministic rollback.
- Abuse and escape testing.

### Acceptance criteria

Each advanced capability must:

- Use bounded resource budgets.
- Integrate with cache keys and purge.
- Expose metrics and audit.
- Have runtime tests.
- Have a clear safe default.
- Not degrade the core edge path when disabled.
- Be independently deployable or removable where appropriate.

### Validation

- Unit and integration tests for each service.
- Smoke coverage for enabled capability.
- Full end-to-end request workflow.
- Resource exhaustion tests.
- Security review.
- Performance benchmark.
- User and operator documentation.
- Rollout and rollback.

---

## Phase 24 — Hosting-provider and commercial platform features

### Objective

Optionally support hosting-provider workflows without making them a dependency of the private-CDN core.

### Scope candidates

- Plans.
- Quotas.
- Usage-meter export.
- Bandwidth and request summaries.
- Overage events.
- Customer onboarding.
- Reseller hierarchy.
- Branding.
- Support roles.
- Service suspension and reactivation.
- Invoice integration hooks.
- Tax and payment processing only through external providers.
- Cost-allocation reports.
- Abuse workflow.
- Data retention by plan.

### Guardrails

- Keep billing separate from request-path decisions.
- Do not block healthy edge traffic solely because the billing service is unavailable.
- Use signed and auditable usage aggregation.
- Document estimation and late-arriving events.
- Provide corrections.
- Protect tenant data.
- Avoid claiming financial accuracy without reconciliation tests.
- Keep this phase optional and lower priority.

### Acceptance criteria

- Usage exports reconcile against reference datasets.
- Quota enforcement is explicit and audited.
- Billing integration failure does not break core CDN delivery unexpectedly.
- Tenant and reseller boundaries pass security tests.
- Financially relevant changes have immutable audit history.

### Validation

- Aggregation and reconciliation tests.
- Smoke for plan and quota API.
- End-to-end tenant usage workflow.
- Failure and correction workflow.
- Security isolation tests.
- Documentation and legal/compliance limitations.

---

## Phase 25 — Contributor and ecosystem maturity

### Objective

Make each product area understandable and safely extensible by contributors.

### Scope

Add architecture and extension documentation for:

- PHP control plane.
- PostgreSQL schema and jobs.
- Vue dashboard.
- OpenResty/Lua edge.
- Edge agent.
- PowerDNS and DNSGeo.
- Cache.
- Security rules.
- Challenge.
- Waiting room.
- Metrics and events.
- CI and evidence reports.
- Deployment topologies.

Document how to add:

- Database state.
- API endpoint.
- OpenAPI contract.
- Dashboard feature.
- Snapshot field.
- Edge action.
- Cache behavior.
- Metric.
- Security event.
- Audit event.
- Background job.
- Smoke assertion.
- End-to-end workflow.
- Failure test.
- Runbook.

Improve:

- Issue templates.
- Pull-request template.
- Code ownership or reviewer guidance.
- Development fixtures.
- Local debugging.
- Release notes.
- Good-first-issue process.
- Architecture decision records.
- Compatibility policy.
- Deprecation process when required.

### Acceptance criteria

- Contributors can identify all layers required for a cross-cutting feature.
- PR templates require tests, smoke, e2e, docs, and roadmap impact.
- New runtime actions follow a documented extension and precedence model.
- Releases, changelog, docs, roadmap, and artifacts remain synchronized.

### Validation

- Documentation-link tests.
- Example validation.
- Template checks.
- Contributor quickstart smoke test.
- Example extension end-to-end test.
- Documentation build.
- Release metadata check.

---

## 9. Cross-cutting requirements

These requirements apply throughout all phases.

### 9.1 Security

- Threat-model every new trust boundary.
- Validate and normalize hosts, paths, redirects, headers, and identifiers.
- Prevent open redirects.
- Prevent header injection.
- Use constant-time verification for signatures and secrets.
- Keep secrets out of logs, metrics, browser state, public snapshots, and support bundles.
- Bound token, cookie, body, header, queue, event, and policy size.
- Preserve explicit deny decisions.
- Protect administrative mutations with authorization and CSRF controls where applicable.
- Record sensitive changes in audit logs.
- Document privacy and retention.
- Test IPv4 and IPv6.
- Define trusted proxy behavior.
- Rate-limit edge-only utility endpoints.
- Avoid arbitrary code execution in the primary request process.

### 9.2 Performance budgets

Every phase that changes a request path or query must define:

- Baseline.
- Target.
- Reference hardware.
- Dataset or traffic model.
- p50, p95, and p99.
- CPU.
- Memory.
- Disk.
- Network.
- Error rate.
- Saturation behavior.
- Regression threshold.

Recommended global budgets to refine through measurement:

- Bounded edge-added latency for cache hits.
- Bounded configuration propagation time.
- Bounded purge propagation.
- Bounded analytics response size.
- Bounded queue memory.
- Bounded telemetry disk use.
- Bounded recovery time after transient dependency failure.

### 9.3 Availability and graceful degradation

Define behavior when unavailable:

- Core API.
- PostgreSQL.
- PowerDNS.
- DNS resolver.
- Edge agent.
- Metrics collector.
- Security-event collector.
- ACME.
- Origin.
- Shield.
- Shared rate-limit or queue coordinator.
- Identity provider.
- Monitoring stack.

Prefer:

- Last-known-good configuration.
- Cached traffic.
- Bounded retry.
- Clear stale-state visibility.
- No unlimited queue growth.
- No silent success.
- No accidental fail-open for explicit security blocks.
- Documented fail-open/fail-closed choices.

### 9.4 Data lifecycle

For each stored data type define:

- Purpose.
- Schema.
- Owner.
- Retention.
- Pruning.
- Export.
- Backup.
- Restore.
- Deletion.
- Privacy sensitivity.
- Index-growth expectation.
- Cardinality.
- Maximum event size.
- Maximum query range.

### 9.5 Accessibility and user experience

- Keyboard navigation.
- Screen-reader labels.
- Color-independent state communication.
- Clear loading, empty, error, partial, and stale states.
- Confirmation for destructive operations.
- Request IDs and actionable error text.
- Time-zone clarity.
- Advanced details available without forcing internal jargon on all users.

### 9.6 Compatibility

- The project currently favors a clean authoritative fresh-install schema.
- Avoid historical compatibility layers unless explicitly required.
- Record breaking changes.
- Update examples and deployment files.
- Remove dead behavior when replacing it.
- Introduce formal API compatibility and deprecation policy before promising stable external APIs.

---

## 10. Test strategy

### 10.1 Test layers

#### Unit

Validate isolated logic:

- Parsers.
- Validators.
- Matchers.
- Token signing.
- State machines.
- Cache decisions.
- Rollup calculations.
- Policy generation.

#### Integration

Validate component boundaries:

- PHP and PostgreSQL.
- Snapshot generation.
- Job workers.
- PowerDNS API.
- ACME staging client.
- Edge Lua modules.
- Agent queue behavior.
- Dashboard API client.

#### Smoke

Validate a clean running stack:

- Health.
- Authentication.
- Dashboard availability.
- Database.
- Edge identity.
- Active config.
- Basic DNS.
- Basic cache.
- Basic security.
- Basic telemetry.

Smoke tests should remain fast and focused.

#### End to end

Validate real product workflows across layers:

```text
dashboard/API
  -> database
  -> snapshot or worker
  -> edge/DNS/TLS runtime
  -> telemetry
  -> API
  -> dashboard/report
```

#### Stress and load

Use the Phase 15 capability matrix and common runner. Validate:

- Edge throughput.
- Cache lock.
- Origin failure.
- Event floods.
- Queue bounds.
- Analytics scale.
- DNS scale.
- Purge fan-out.
- Config fleet scale.
- Dashboard large datasets.
- Database workload isolation and reporting under ingest load.
- Sustained soak, dependency failure, and measured recovery.
- Post-stress smoke and end-to-end correctness.

#### Failure injection

Validate:

- Restarts.
- Timeouts.
- Dependency outages.
- Partial writes.
- Malformed snapshots.
- Disk pressure.
- Network partitions where practical.
- Worker duplication.
- Clock skew.
- Expired credentials.

#### Security

Validate:

- Authorization.
- Tenant isolation.
- Injection.
- Open redirect.
- Signature mutation.
- Replay.
- Header spoofing.
- Cache poisoning.
- Resource exhaustion.
- Secret leakage.
- Request smuggling defenses where applicable.
- Dependency and image scanning.

#### Compatibility

Validate:

- Supported browsers.
- HTTP clients.
- IPv4 and IPv6.
- Common origin servers.
- TLS versions.
- PowerDNS versions supported by the project.
- Multi-architecture images.

### 10.2 Evidence format

Every phase evidence report should contain:

```markdown
# Phase XX Evidence

- Phase:
- Status:
- Owner:
- Commit:
- Pull requests:
- Date:
- Environment:
- Reference hardware:
- Dataset or traffic model:

## Implementation
- Files changed:
- Schema changes:
- API changes:
- Edge changes:
- Dashboard changes:
- Code removed:

## Validation
- Unit/integration:
- Dashboard:
- Smoke:
- End to end:
- DNS end to end:
- Load/stress:
- Failure injection:
- Security:
- Documentation build:

## Performance
- Baseline:
- Result:
- Regression threshold:
- Artifacts:

## Operations
- Rollout:
- Rollback:
- Environment variables:
- Breaking changes:
- Known limitations:

## Documentation
- User:
- Operator:
- API/OpenAPI:
- Architecture:
- Security:
- Troubleshooting:
- Runbooks:
- Changelog:
- Roadmap:
```

---

## 11. Progress update template

Add this section under each active phase or maintain it in the phase evidence report.

```markdown
### Progress

- Status: Planned
- Priority: P0
- Owner: Unassigned
- Tracking issue:
- Pull requests:
- Last updated: YYYY-MM-DD

#### Completed
- [ ] Milestone

#### Remaining
- [ ] Milestone

#### Blockers
- None

#### Validation
- Unit/integration:
- Dashboard:
- Smoke:
- End to end:
- DNS end to end:
- Load/stress:
- Failure injection:
- Security:

#### Documentation
- User/admin:
- API/OpenAPI:
- Architecture/security:
- Troubleshooting/runbooks:
- Upgrade/rollback:
- Changelog:
- Root/docs roadmap sync:

#### Evidence
- Report:
- CI run:
- Benchmark:
```

Update progress:

- When implementation begins.
- When a milestone merges.
- When scope changes.
- When validation starts.
- When validation discovers a blocker.
- Before release.
- After release.
- When a phase is deferred.
- When a known limitation changes.

Do not mark a phase Complete based only on code review, compilation, UI presence, database fields, or source-string assertions.

---


## 12. Immediate execution order

Execute the roadmap in this order:

1. **Phase 0 — Governance and capability audit**
2. **Phase 1 — Database architecture and real-time reporting**
3. **Phase 2 — Analytics scalability**
4. **Phase 3 — Edge hot-path performance**
5. **Phase 4 — Real challenge**
6. **Phase 5 — Overload protection**
7. **Phase 6 — Cache correctness**
8. **Phase 7 — Origin resilience**
9. **Phase 8 — Purge and invalidation**
10. **Phase 10 — DNS and GeoDNS reliability**
11. **Phase 11 — TLS lifecycle**
12. **Phase 12 — WAF, rate limits, and API protection**
13. **Phase 9 — Protocol and delivery performance**
14. **Phase 13 — Observability**
15. **Phase 14 — Dashboard, API, CLI, and onboarding**
16. **Phase 15 — Full-platform stress and recovery qualification**
17. **Phase 16 — Secret and release security**
18. Continue with P2 and P3 phases after P0 and P1 evidence is accepted.

Parallel work is allowed when ownership is separate and dependencies are respected:

- Phase 1 data architecture must define the storage and workload boundaries used by Phase 2.
- Phases 2 and 3 may run in parallel after Phase 1 interfaces and data contracts are agreed.
- Phase 4 signing primitives should be reusable by Phase 5.
- Phase 6 cache semantics should be stable before Phase 8 advanced purge behavior.
- Phase 7 origin resilience should be stable before origin shielding is declared stable.
- Phase 10 DNS reliability and Phase 11 TLS may run in parallel with coordinated ACME tests.
- Phase 13 observability should add metrics continuously as earlier phases land.
- Phase 15 stress scenarios must be added continuously; the complete release gate occurs after the relevant P0 and P1 features exist.
- Enterprise identity and Kubernetes packaging must not delay P0 runtime correctness.

---

## 13. Suggested release milestones

These are capability milestones, not promised dates.

### Milestone A — Truthful and measurable platform

Includes:

- Phase 0 complete.
- Capability matrix.
- Roadmap evidence gates.
- Unsupported controls hidden or marked Experimental.
- Root/docs roadmap sync.

Exit statement:

> CDNLite clearly distinguishes implemented runtime capabilities from planned or experimental features.

### Milestone B — Scalable data, reporting, and edge foundation

Includes:

- Phase 1 complete.
- Phase 2 complete.
- Phase 3 complete.
- Workload-isolated database design.
- Batch telemetry ingestion.
- Current-state read models.
- Incremental rollups.
- Bounded analytics.
- Cached worker configuration.
- Buffered bounded telemetry.

Exit statement:

> CDNLite control-plane data, reporting, ingestion, and edge request processing remain bounded under the documented reference workload.

### Milestone C — Complete traffic-protection workflows

Includes:

- Phase 4 complete.
- Phase 5 complete.
- Real challenge.
- Clearance.
- Waiting room.
- Origin admission control.
- Security and load evidence.

Exit statement:

> Challenge and overload controls perform their documented runtime behavior and are covered by browser, API, security, load, and recovery tests.

### Milestone D — Correct private CDN delivery

Includes:

- Phase 6 complete.
- Phase 7 complete.
- Phase 8 complete.
- Correct caching.
- Resilient origins.
- Observable purge.

Exit statement:

> CDNLite provides a dependable private CDN request path with tested cache, origin, and invalidation semantics.

### Milestone E — Controlled production readiness

Includes:

- Phases 9 through 16 complete.
- Protocol delivery.
- DNS.
- TLS.
- Security.
- Observability.
- Operator UX.
- Full-platform stress and recovery qualification.
- Secret rotation.
- Signed releases.
- Production runbooks.

Exit statement:

> CDNLite is ready for documented controlled production use on the qualified topology, subject to the measured capacity and stated availability limits.

### Milestone F — Enterprise private CDN

Includes:

- Phases 17 through 21 complete.
- Backup/restore.
- HA guidance.
- RBAC.
- SSO.
- Tenant isolation.
- Policy as code.

Exit statement:

> CDNLite supports enterprise private-CDN administration with tested identity, isolation, recovery, and policy workflows.

### Milestone G — Deployment and service ecosystem

Includes:

- Phases 22, 23, and 25 complete.
- Kubernetes and Terraform.
- Fleet rollout.
- Selected advanced services.
- Contributor extension paths.

Exit statement:

> CDNLite can be deployed and extended through documented, tested infrastructure and contribution workflows.

---

## 14. Production-readiness checklist

A deployment must not be described as production-ready until the applicable checks are complete.

### Control plane

- [ ] TLS enabled.
- [ ] Default credentials removed.
- [ ] External authentication or native enterprise identity enabled.
- [ ] Database private.
- [ ] PowerDNS API private.
- [ ] Backup and restore drill passed.
- [ ] Worker duplication is safe.
- [ ] Health and alerts configured.
- [ ] Secrets stored outside the repository.
- [ ] Rotation runbooks tested.

### Edge

- [ ] Production worker and connection settings.
- [ ] Active config and age monitored.
- [ ] Last-known-good behavior tested.
- [ ] Telemetry bounded.
- [ ] Cache correctness tests pass.
- [ ] Origin failure behavior tested.
- [ ] TLS policy reviewed.
- [ ] Request and body limits configured.
- [ ] WAF/rate rules staged.
- [ ] Challenge and overload behavior tested if enabled.
- [ ] Disk and memory monitored.

### DNS and TLS

- [ ] Delegation verified.
- [ ] Authoritative answers tested.
- [ ] GeoDNS fallback tested.
- [ ] DNS failure alerts configured.
- [ ] Certificate issuance and renewal tested.
- [ ] Expiry alerts configured.
- [ ] Emergency certificate replacement documented.

### Data and observability

- [ ] Retention configured.
- [ ] Database workload pools and statement budgets configured.
- [ ] Analytics queries bounded.
- [ ] Current-state read models and reporting freshness monitored.
- [ ] Database partition, index, vacuum, growth, and rollup health monitored.
- [ ] Rollup lag monitored.
- [ ] Event export access controlled.
- [ ] Logs redacted.
- [ ] Request correlation enabled.
- [ ] Disk growth monitored.
- [ ] Support bundle reviewed for secret safety.

### Operations

- [ ] Ownership defined.
- [ ] Incident contacts defined.
- [ ] Rollback tested.
- [ ] Upgrade staging process defined.
- [ ] Capacity limits documented.
- [ ] Known limitations accepted.
- [ ] Smoke and e2e pass on the deployed topology.
- [ ] Applicable stress evidence exists.
- [ ] Full-platform release stress report passes for the deployed topology.
- [ ] Post-stress smoke, end-to-end, queue-drain, rollup, DNS, and config-recovery checks pass.

---

## 15. Not planned as near-term commitments

The following are not near-term commitments:

- Operating CDNLite as a managed CDN service.
- Claiming hyperscale global CDN parity.
- Network-layer scrubbing-center DDoS mitigation.
- Automatic global anycast network operation.
- Compliance certification claims without an independent certification program.
- Full payment processing.
- Arbitrary untrusted tenant code inside the primary OpenResty request process.
- A browser or device-fingerprinting system marketed as guaranteed human verification.
- Unlimited analytics retention.
- Unlimited event ingestion.
- Unlimited cache, purge, policy, or queue state.
- Selectable features that are not enforced and tested at runtime.

---

## 16. Roadmap maintenance policy

Review this roadmap:

- After every major release.
- After a security incident.
- After a significant production failure.
- After a benchmark invalidates a scale assumption.
- After a stress, soak, or recovery qualification discovers a new capacity or failure limit.
- When a phase changes scope.
- When a feature changes from Experimental to Stable.
- When a known limitation is removed or discovered.

A roadmap update must:

- Preserve honest current status.
- Link evidence for completed work.
- State newly discovered limitations.
- Remove obsolete work.
- Avoid duplicating the same milestone under multiple phases.
- Keep `ROADMAP.md`, `docs/roadmap.md`, `README.md`, enterprise-readiness documentation, and changelog claims consistent.
