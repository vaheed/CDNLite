# Production Readiness

[Back to docs index](index.md)

This page tracks what is safe today and what must be addressed before exposing CDNLite to the public internet.

## Current Status

- Safe for local development and controlled internal test environments.
- Not safe for direct internet exposure in current default form because most control-plane endpoints do not require application-level API auth.
- Edge-to-core signed auth is implemented for edge agent flows only.

## Internet Exposure Risks

- Control-plane admin APIs for sites, DNS, redirects, rate limits, WAF, cache rules, edge node listing, and usage summary are reachable without app bearer auth.
- If `CDNLITE_DNS_PROVIDER=powerdns` with write credentials, unauthenticated API writes can mutate DNS records.
- TLS certificate lifecycle automation is not implemented; cert state/expiry tracking is also not yet implemented.

## Required Secrets And Hygiene

Minimum secrets to rotate and protect:

- `EDGE_SHARED_TOKEN` for edge signed requests.
- `CDNLITE_DNS_POWERDNS_API_KEY` when PowerDNS integration is enabled.
- `DB_PASSWORD` (and DB network access controls).

Recommended hardening before production:

- Put core behind private networking or API gateway allowlists.
- Add application bearer auth for non-edge control-plane endpoints.
- Enable HTTPS termination and strict firewall rules.
- Rotate edge token and DNS credentials regularly.

## Cache And Purge Reality

- OpenResty edge cache is active and supports cache-rule-based eligibility and TTL.
- Cache bypass exists for unsafe methods and selected request headers.
- Purge API is not implemented yet; cache invalidation is currently time-based (TTL expiry).

## Go/No-Go Checklist

- API auth for admin endpoints is implemented and tested.
- Secrets are not using local/dev defaults.
- Core is not publicly reachable without network controls.
- TLS certificate management and monitoring workflow is defined.
- PowerDNS access is restricted and audited if enabled.
