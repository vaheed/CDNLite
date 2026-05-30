# Security Model

## Security Scope
This document covers control-plane and edge-to-core protections implemented in CDNLite.

## Edge Authentication
Protected endpoints require:
- bearer token
- edge id header match
- timestamp window validation
- nonce replay protection
- request signature validation

Protected endpoints:
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/config`
- `POST /api/v1/collector/usage`

## Token Handling
- Tokens are stored as hashes in DB (`edge_tokens`).
- Rotation supported via CLI command.

## Replay Protection
- Nonces stored with TTL in `edge_request_nonces`.
- Duplicate nonce per edge id is rejected.

## Recommended Hardening
- Place core behind TLS terminator/reverse proxy.
- Rotate edge tokens regularly.
- Restrict source networks allowed to call core control endpoints.
- Protect CI secrets and registry credentials.

## Current Limitations
- No user auth/RBAC layer for operator APIs in current baseline.
- No signed config artifact verification at edge beyond transport auth path.
