---
title: Dashboard Reporting
description: Admin reporting API and dashboard metrics for CDNLite private CDN operations, usage, DNS, edge health, SSL, cache purge, audit, and security events.
---

# Dashboard Reporting

CDNLite's admin overview uses authenticated report endpoints under
`/api/v1/reports/*`. Reports are built from existing PostgreSQL control-plane,
usage, DNS, edge, SSL, job, cache purge, and audit tables. Widgets must not use
mock data; when an edge ingest field is not available, the API returns `null`
and includes an `unavailable` explanation.

## Query Parameters

All report endpoints accept:

- `domain_id`: optional domain UUID. Unknown domains return `404`.
- `from`: optional Unix timestamp. Defaults to 24 hours before `to`.
- `to`: optional Unix timestamp. Defaults to current server time.
- `bucket`: `minute`, `hour`, or `day`. Defaults to `hour`.
- `compare`: optional boolean. `summary` returns previous-range deltas when true.
- `limit`: optional top-list limit, clamped between 1 and 100.

## Implementation Map

| Metric/report | Existing endpoint? | Backend change needed? | Frontend component | Test coverage |
| --- | --- | --- | --- | --- |
| Executive KPIs and warnings | Partial: `/api/v1/overview` | Added `/api/v1/reports/summary` with deltas and ranked warnings | `dash/src/views/OverviewView.vue` | `core/tests/test_reports_contract.py` |
| Traffic time series and top lists | Partial: `/api/v1/usage/summary`, activity APIs | Added `/api/v1/reports/traffic` over `usage_rollups`; visitor IPs and countries come from edge `client_ip` and `client_country` metrics | Overview traffic charts and tables | `core/tests/test_reports_contract.py` |
| Cache distribution and purge timeline | Partial: `/api/v1/analytics/cache` | Added `/api/v1/reports/cache` over `usage_rollups` and `cache_purge_requests` | Overview cache chart | `core/tests/test_reports_contract.py` |
| Edge health and traffic | Partial: `/api/v1/edge/nodes` | Added `/api/v1/reports/edge` over `edge_nodes`, config snapshots, and usage | Overview edge health panel | `core/tests/test_reports_contract.py` |
| Security events | Partial: `/api/v1/security/events`, `/api/v1/security/summary` | Added `/api/v1/reports/security` over security audit events | Overview security chart | `core/tests/test_reports_contract.py` |
| Reliability | Partial: DNS, SSL, jobs, origins APIs | Added `/api/v1/reports/reliability` over DNS sync, SSL, jobs, and origins | Overview reliability panel | `core/tests/test_reports_contract.py` |
| Operations | Partial: `/api/v1/events`, `/api/v1/audit`, `/api/v1/jobs` | Added `/api/v1/reports/operations` over audit, DNS events, SSL jobs, snapshots. Actor and resource rankings use a bounded recent audit sample so high-volume production audit logs cannot exceed the reporting statement timeout. If an operations subsection still times out, the endpoint returns the remaining data with an `unavailable` map and logs `operations_report_section_timeout` with the section name. | Overview jobs table and failed-job chart | `core/tests/test_reports_contract.py` |

## Unavailable Fields

The current edge ingest does not provide complete data for these report fields:

- `cache.cache_rule_match_counts`: request ingest has optional `rule_id`, but it
  is not guaranteed to identify matched cache rules for every request.
- `security` complete WAF allow logs: security ingest records WAF matches,
  rate-limited events, bot matches, and decisions, but does not emit a full allow
  stream for all requests.

These fields remain `null` or documented in `unavailable`; they are not
fabricated in the dashboard.

Visitor country reports are available when the edge can resolve a country from
`X-CDNLITE-Country`, `CF-IPCountry`, or the mounted MaxMind MMDB. When no country
can be resolved, the edge records `DEFAULT`, and reports group that value instead
of inventing a country.

## Indexes

Reporting adds time-range indexes for `usage_rollups`, `audit_log`, `ssl_jobs`,
`cache_purge_requests`, and SSL expiry lookups. The fresh-install schema and
`000016_reporting_indexes.sql` migration both include the same indexes.
