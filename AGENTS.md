# Repository Agent Notes

## Scope

These instructions apply to the entire repository unless a narrower `AGENTS.md` exists in a subdirectory.

This repository is one product surface: Core API, admin panel, user panel, edge runtime, PowerDNS/DNSGeo integration, Docker Compose, CI, docs, and examples must stay aligned.

## Product Priorities

The current priority is to make DNS, proxy routing, and PowerDNS sync reliable and production-ready.

Agents must preserve these product goals:

* PowerDNS records must be truly written to the real/local PowerDNS API, not only mocked in tests.
* Core must stay continuously synced with PowerDNS/DNSGeo.
* Proxied apex domains (`@`) must be supported by using PowerDNS `ALIAS`, not by writing flattened A/AAAA IPs from Core.
* Proxied subdomains such as `www` should normally use CNAMEs to stable CDN hostnames.
* Edge IP changes must not require rewriting every customer domain when the domain is proxied.
* DNSGeo must be the project PowerDNS/GeoDNS implementation.
* Docker Compose must not rely on `docker compose --profile`.
* Admin panel, user panel, API, CLI, docs, examples, tests, smoke tests, and e2e tests must match the same behavior.

## Change Discipline

* Treat code, tests, docs, examples, Compose, and CI as one product surface.
* After every change that implements, advances, blocks, or changes the scope of
  roadmap work, update the progress section in `docs/ROADMAP.md` in the same
  change.
* Roadmap progress entries must state the date, completed work, validation run,
  remaining gaps, and must not mark a phase complete before all acceptance
  criteria for that phase have passed.
* Any behavior change must include matching tests or CI checks.
* If a test is not practical, document the reason in the final handoff.
* Any user-visible behavior, command, endpoint, config, environment variable, script flow, or operational behavior change must update the relevant docs in the same change.
* Keep public API behavior stable unless the task explicitly asks for a breaking change.
* When renaming or replacing an environment variable, keep a documented deprecation alias unless the task explicitly asks for removal.
* Keep shell scripts portable to their declared shell:

  * agent/runtime scripts: POSIX `sh`
  * CI scripts: Bash
* Avoid live external service mutation during validation when a local service/mock exists.
* Prefer deterministic behavior over hidden background magic.
* Prefer idempotent sync and reconciliation over one-off write paths.

## DNS and PowerDNS Rules

### Required DNS Model

The desired proxied DNS model is:

* Customer apex `@`:

  * use PowerDNS `ALIAS`
  * target a stable CDN hostname such as `<site-id>.cdn.<base-domain>` or another configured canonical CDN target
  * do not write Core-generated flattened A/AAAA records for proxied apex unless explicitly requested for an emergency fallback
* Customer subdomains such as `www`:

  * use plain `CNAME` to the stable CDN hostname
* CDN/edge zone:

  * use PowerDNS/DNSGeo Lua/GeoDNS records to select healthy edge IPs
  * update shared CDN records when edge pools change, not every customer domain
* Non-proxied records:

  * publish the user’s explicit DNS record values without proxy rewriting

### PowerDNS ALIAS Requirements

When implementing or changing apex proxy behavior, ensure the local/project PowerDNS configuration supports ALIAS expansion:

* `expand-alias=yes`
* a configured resolver suitable for ALIAS expansion
* DNSSEC behavior must be documented if enabled
* tests must verify that apex `ALIAS` resolves to the same effective IP set as the CDN target at the same point in time

### PowerDNS Sync Requirements

PowerDNS sync must be production-ready:

* Use idempotent desired-state reconciliation.
* Use `PATCH`/rrset replacement safely.
* Include retries/backoff for temporary failures.
* Avoid concurrent sync races with a lock or equivalent guard.
* Store sync status in Core.
* Expose sync status in `/cdn-health` or the current health/status endpoint.
* Detect and report stale or failed PowerDNS writes.
* Tests must prove records are actually added to PowerDNS and not only calculated in Core.

## DNSGeo Integration Rules

DNSGeo is the project’s PowerDNS/GeoDNS implementation.

When changing PowerDNS or GeoDNS behavior:

* Integrate DNSGeo into the project’s normal root `docker-compose.yml` flow.
* Do not rely on `docker compose --profile`.
* Do not add extra Compose override files for CI unless explicitly requested.
* Keep local development, CI, smoke, and e2e on the same root Compose topology.
* Use environment variables for job-specific behavior.
* Document how Core connects to DNSGeo/PowerDNS.
* Document the PowerDNS API URL, API key, zone names, ALIAS behavior, resolver settings, Lua/GeoDNS behavior, and admin panel URL.
* Keep DNSGeo service health checks strict enough that CI does not run DNS assertions before PowerDNS is ready.

Expected normal startup should be based on:

```sh
docker compose up -d --build
```

## Config Snapshot Guidance

Config snapshot functionality must either be useful and documented or removed/replaced by a clearer mechanism.

If kept, config snapshots must have a clear purpose, such as:

* exporting deterministic edge configuration for agents
* giving admins an inspectable view of the active routing/config state
* helping debug mismatches between Core state and edge/DNS behavior
* providing a versioned snapshot used by CI/e2e assertions

If config snapshots affect runtime behavior:

* document when snapshots are generated
* document who consumes them
* document how stale snapshots are detected
* expose snapshot status in admin/debug UI
* test snapshot generation and consumption

Do not leave config snapshots as unused or unexplained output.

## Admin Panel and User Panel Requirements

Any DNS/proxy behavior change must be reflected in the relevant UI.

Admin panel should make these easy to inspect/configure:

* PowerDNS/DNSGeo connection status
* last successful sync time
* last sync error
* configured base domain and CDN zone
* PowerDNS ALIAS readiness
* resolver status if detectable
* edge list with region, IP, anycast, health, heartbeat, and enabled state
* generated CDN/GeoDNS records or a safe preview
* sync/retry action for operators, if supported

User panel should make these easy to understand:

* whether a domain/record is proxied
* what target is created for apex `@`
* what target is created for `www` and other subdomains
* whether DNS is pending, synced, degraded, or failed
* actionable setup instructions for nameservers/CNAME/ALIAS behavior

Frontend changes must align with backend behavior and API contracts. Do not add backend DNS features without updating the admin/user UI when the feature is user-visible.

## CI Layout

* CI must use the root `docker-compose.yml`.
* CI must not depend on Docker Compose profiles.
* Do not add extra Compose override files for CI jobs unless explicitly requested.
* Use environment variables for job-specific behavior.
* Keep local Compose and CI Compose behavior as close as practical.
* When changing CI, update `.github/workflows/ci.yml`, `docker-compose.yml`, and `docs/setup.md` together.
* PowerDNS/DNSGeo e2e must start through the normal Compose path.
* Plain e2e and PowerDNS e2e may differ by environment variables, not by Compose profiles.

## DNS Test Requirements

DNS tests must verify real behavior, not only internal planner output.

For PowerDNS/DNSGeo changes, include tests that prove:

* Core creates or updates the expected PowerDNS zone.
* Proxied apex `@` becomes PowerDNS `ALIAS`.
* Proxied `www`/subdomain records become CNAMEs to the CDN hostname.
* CDN hostnames exist in the CDN zone.
* Lua/GeoDNS records are present where expected.
* PowerDNS sync status reports success after a successful write.
* PowerDNS sync status reports failure/degraded state after a simulated failure.
* Edge health changes affect CDN/GeoDNS answers within the expected sync interval.
* Apex ALIAS and the CDN target resolve to the same effective IP set at the same instant.
* Updating an edge IP does not rewrite all proxied customer domains.

## Scale and Stress Requirements

Before considering DNS/PowerDNS work production-ready, include late-phase scale/stress checks.

Minimum target scenario:

* 10,000 domains
* 1,000 DNS records per large test domain where practical, or an equivalent documented load model
* multiple proxied apex records
* multiple proxied subdomain CNAMEs
* at least two regions
* edge IP change event
* edge health transition event
* PowerDNS sync during load

The scale test must verify:

* proxied customer domains are not all rewritten when one edge IP changes
* shared CDN/GeoDNS records update instead
* sync duration is measured
* API remains responsive enough for admin/user operations
* failed sync attempts are visible and recoverable
* database indexes are sufficient for the load

## Final Handoff Requirements

Every final handoff must include:

* what changed
* tests/checks run
* tests/checks not run and why
* docs updated
* env vars added/changed/deprecated
* migration/backward compatibility notes
* known risks or follow-up work

Do not claim production readiness unless smoke/e2e and the relevant stress checks have passed or the remaining gaps are clearly documented.
