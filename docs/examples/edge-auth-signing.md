# Edge Auth Signing

[Back to docs index](../index.md)

Edge auth signs the exact method, path, timestamp, nonce, and raw body. The query string is not part of the signed path in current routes.

## Canonical Format

```text
UPPERCASE_METHOD
PATH_WITHOUT_QUERY
UNIX_TIMESTAMP
NONCE
SHA256_RAW_BODY_HEX
```

Signature:

```text
hex_hmac_sha256(canonical, sha256(token_as_hex_string))
```

## Shell Example

```bash
EDGE_ID=edge-local-1
EDGE_TOKEN=edge-dev-token
CORE_URL=http://localhost:8080
METHOD=POST
PATH_ONLY=/api/v1/edge/heartbeat
BODY='{"edge_id":"edge-local-1"}'
TS="$(date +%s)"
NONCE="$(openssl rand -hex 12)"
BODY_HASH="$(printf '%s' "$BODY" | sha256sum | awk '{print $1}')"
CANONICAL="$(printf '%s
%s
%s
%s
%s' "$METHOD" "$PATH_ONLY" "$TS" "$NONCE" "$BODY_HASH")"
KEY="$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')"
SIG="$(printf '%s' "$CANONICAL" | openssl dgst -sha256 -hmac "$KEY" -binary | od -An -tx1 | tr -d ' 
')"

curl -s -X "$METHOD" "$CORE_URL$PATH_ONLY" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $EDGE_TOKEN" \
  -H "X-CDNLITE-Edge-Id: $EDGE_ID" \
  -H "X-CDNLITE-Timestamp: $TS" \
  -H "X-CDNLITE-Nonce: $NONCE" \
  -H "X-CDNLITE-Signature: $SIG" \
  -d "$BODY"
```

Expected output after the edge has registered:

```json
{"ok":true}
```

## PHP Example

```php
<?php
$token = 'edge-dev-token';
$method = 'POST';
$path = '/api/v1/edge/heartbeat';
$body = '{"edge_id":"edge-local-1"}';
$timestamp = time();
$nonce = bin2hex(random_bytes(12));
$canonical = strtoupper($method) . "
" . $path . "
" . $timestamp . "
" . $nonce . "
" . hash('sha256', $body);
$signature = hash_hmac('sha256', $canonical, hash('sha256', $token));
```

## Failure Examples

Missing headers:

```json
{"error":"edge_auth_required"}
```

Wrong token:

```json
{"error":"edge_auth_invalid_token"}
```

Wrong body, path, timestamp, nonce, or HMAC:

```json
{"error":"edge_auth_invalid_signature"}
```

Reused nonce:

```json
{"error":"edge_auth_replay_detected"}
```

Stale timestamp:

```json
{"error":"edge_auth_timestamp_out_of_range"}
```
