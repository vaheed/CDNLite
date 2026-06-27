local cjson = require('cjson.safe')

local M = {}

local cookie_name = '__cdnlite_clearance'
local default_ttl = 1800

local function secret()
  local value = os.getenv('CDNLITE_EDGE_CLEARANCE_SECRET') or ''
  if value == '' then
    return 'cdnlite-dev-clearance-secret'
  end
  return value
end

local function b64(value)
  return (ngx.encode_base64(value or ''):gsub('+', '-'):gsub('/', '_'):gsub('=', ''))
end

local function unb64(value)
  local padded = tostring(value or ''):gsub('-', '+'):gsub('_', '/')
  local remainder = #padded % 4
  if remainder > 0 then
    padded = padded .. string.rep('=', 4 - remainder)
  end
  return ngx.decode_base64(padded)
end

local function sign(payload)
  return b64(ngx.hmac_sha1(secret(), payload))
end

local function token_payload(domain_id, action, rule_id, client_ip, expires_at, nonce)
  return table.concat({
    tostring(domain_id or ''),
    tostring(action or ''),
    tostring(rule_id or ''),
    tostring(client_ip or ''),
    tostring(expires_at or ''),
    tostring(nonce or ''),
  }, '|')
end

local function encode_token(claims)
  local json = cjson.encode(claims)
  if not json then
    return nil
  end
  local payload = b64(json)
  return payload .. '.' .. sign(payload)
end

local function decode_token(token)
  local payload, signature = tostring(token or ''):match('^([A-Za-z0-9_-]+)%.([A-Za-z0-9_-]+)$')
  if not payload or not signature then
    return nil, 'malformed'
  end
  if sign(payload) ~= signature then
    return nil, 'bad_signature'
  end
  local decoded = unb64(payload)
  if not decoded then
    return nil, 'bad_payload'
  end
  local claims = cjson.decode(decoded)
  if type(claims) ~= 'table' then
    return nil, 'bad_claims'
  end
  return claims, nil
end

local function cookie_value()
  return ngx.var['cookie_' .. cookie_name]
end

local function scope_matches(claims, domain_id, action, rule_id, client_ip)
  if tostring(claims.domain_id or '') ~= tostring(domain_id or '') then
    return false
  end
  if tostring(claims.action or '') ~= tostring(action or '') then
    return false
  end
  if tostring(claims.rule_id or '') ~= tostring(rule_id or '') then
    return false
  end
  if tostring(claims.client_ip or '') ~= tostring(client_ip or '') then
    return false
  end
  return true
end

function M.has_clearance(domain_id, action, rule_id, client_ip)
  local claims = decode_token(cookie_value())
  if not claims then
    return false
  end
  if tonumber(claims.expires_at or 0) <= ngx.time() then
    return false
  end
  return scope_matches(claims, domain_id, action, rule_id, client_ip)
end

function M.issue(domain_id, action, rule_id, client_ip, ttl)
  local expires_at = ngx.time() + (tonumber(ttl or default_ttl) or default_ttl)
  local nonce = b64(tostring(ngx.now()) .. ':' .. tostring(math.random()) .. ':' .. tostring(ngx.worker.pid()))
  return encode_token({
    domain_id = tostring(domain_id or ''),
    action = tostring(action or ''),
    rule_id = tostring(rule_id or ''),
    client_ip = tostring(client_ip or ''),
    expires_at = expires_at,
    nonce = nonce,
  }), expires_at
end

function M.consume_challenge(domain_id, action, rule_id, client_ip)
  local token = ngx.var.arg_cdnlite_challenge or ''
  if token == '' then
    return false
  end
  local claims, err = decode_token(token)
  if not claims then
    return false, err
  end
  if tonumber(claims.expires_at or 0) <= ngx.time() then
    return false, 'expired'
  end
  if not scope_matches(claims, domain_id, action, rule_id, client_ip) then
    return false, 'scope_mismatch'
  end
  ngx.header['Set-Cookie'] = string.format(
    '%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax',
    cookie_name,
    token,
    math.max(1, tonumber(claims.expires_at or 0) - ngx.time())
  )
  return true
end

function M.challenge_response(domain_id, action, rule_id, client_ip, status_code, error_code)
  local token, expires_at = M.issue(domain_id, action, rule_id, client_ip)
  local uri = tostring(ngx.var.uri or '/')
  local sep = string.find(tostring(ngx.var.request_uri or ''), '?', 1, true) and '&' or '?'
  local challenge_url = tostring(ngx.var.request_uri or uri) .. sep .. 'cdnlite_challenge=' .. ngx.escape_uri(token or '')
  ngx.status = status_code
  ngx.header.content_type = 'application/json'
  ngx.header['Cache-Control'] = 'no-store'
  ngx.say(cjson.encode({
    error = error_code,
    request_id = tostring(ngx.ctx.request_id or ''),
    challenge = {
      type = 'signed_clearance',
      url = challenge_url,
      expires_at = expires_at,
    },
  }))
  return ngx.exit(status_code)
end

return M
