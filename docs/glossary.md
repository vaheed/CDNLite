---
title: CDN Glossary
description: Glossary for self-hosted CDN, private CDN, CDN control plane, edge proxy, GeoDNS, WAF rules, cache purge, and origin health checks.
---

# CDN Glossary

This glossary explains common terms used in CDNLite and private CDN operations.

## Self-Hosted CDN

A CDN operated by your own team rather than consumed as a fully managed public service. CDNLite is a self-hosted CDN control plane and edge platform.

## Private CDN

A CDN-style delivery layer for a company, lab, hosting provider, or internal platform team. A private CDN usually emphasizes control, private routing, isolated operations, and ownership of logs and policy.

## CDN Control Plane

The API, dashboard, database, and jobs that manage domains, DNS records, origins, cache rules, WAF rules, SSL, edges, analytics, and audit logs.

## Edge Proxy

The reverse proxy close to users or applications. CDNLite uses OpenResty/Lua as the edge proxy runtime.

## GeoDNS

DNS routing that can answer differently based on geography, topology, or health. CDNLite uses DNSGeo with PowerDNS to support private edge routing.

## WAF Rules

Web application firewall rules that inspect requests and allow, block, or log traffic based on policy.

## Cache Purge

An operation that removes cached content from the edge so future requests fetch fresh content from the origin.

## Origin Health Checks

Checks that determine whether an origin server is healthy enough to receive traffic.

## Next Steps

- [What is CDNLite?](./what-is-cdnlite.md)
- [Private CDN](./private-cdn.md)
- [Architecture](./architecture.md)
