local cjson = require('cjson.safe')
local resty_sha256 = require('resty.sha256')
local resty_string = require('resty.string')

local M = {}

local cookie_name = '__cdnlite_clearance'
local default_ttl = 1800
local challenge_ttl = 300
local default_difficulty = 3

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

local function hash_hex(value)
  local sha = resty_sha256:new()
  sha:update(value or '')
  return resty_string.to_hex(sha:final())
end

local function html_escape(value)
  return tostring(value or '')
    :gsub('&', '&amp;')
    :gsub('<', '&lt;')
    :gsub('>', '&gt;')
    :gsub('"', '&quot;')
    :gsub("'", '&#39;')
end

local function js_string(value)
  return cjson.encode(tostring(value or '')) or '""'
end

local function same_host_path(value)
  local path = tostring(value or ngx.var.request_uri or '/')
  if path == '' or string.sub(path, 1, 1) ~= '/' or string.sub(path, 1, 2) == '//' then
    return '/'
  end
  if string.find(path, '[\r\n]', 1, false) then
    return '/'
  end
  return path
end

local function proof_prefix(difficulty)
  return string.rep('0', tonumber(difficulty or default_difficulty) or default_difficulty)
end

local function challenge_mode(difficulty)
  local n = tonumber(difficulty or default_difficulty) or default_difficulty
  if n <= 1 then
    return 'browser_check'
  end
  return 'proof_of_work'
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

function M.issue_challenge(domain_id, action, rule_id, client_ip, return_path)
  local expires_at = ngx.time() + challenge_ttl
  local nonce = b64(tostring(ngx.now()) .. ':' .. tostring(math.random()) .. ':' .. tostring(ngx.worker.pid()))
  local difficulty = tonumber(os.getenv('CDNLITE_EDGE_CHALLENGE_DIFFICULTY') or '') or default_difficulty
  if difficulty < 1 then difficulty = 1 end
  if difficulty > 6 then difficulty = 6 end
  return encode_token({
    token_type = 'challenge',
    domain_id = tostring(domain_id or ''),
    action = tostring(action or ''),
    rule_id = tostring(rule_id or ''),
    client_ip = tostring(client_ip or ''),
    return_path = same_host_path(return_path),
    issued_at = ngx.time(),
    expires_at = expires_at,
    difficulty = difficulty,
    mode = challenge_mode(difficulty),
    nonce = nonce,
  }), expires_at, difficulty
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
  if tostring(claims.token_type or 'clearance') ~= 'clearance' then
    return false, 'wrong_token_type'
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

function M.verify_challenge()
  ngx.req.read_body()
  local args = ngx.req.get_post_args(20)
  if not args or not args.token then
    args = ngx.req.get_uri_args(20)
  end
  local claims, err = decode_token(args.token or '')
  if not claims then
    ngx.status = 403
    ngx.header.content_type = 'application/json'
    ngx.say(cjson.encode({ ok = false, error = err or 'invalid_challenge' }))
    return ngx.exit(403)
  end
  if tostring(claims.token_type or '') ~= 'challenge' then
    ngx.status = 403
    ngx.header.content_type = 'application/json'
    ngx.say(cjson.encode({ ok = false, error = 'wrong_token_type' }))
    return ngx.exit(403)
  end
  if tonumber(claims.expires_at or 0) <= ngx.time() then
    ngx.status = 403
    ngx.header.content_type = 'application/json'
    ngx.say(cjson.encode({ ok = false, error = 'expired' }))
    return ngx.exit(403)
  end
  local client_ip = tostring(ngx.var.remote_addr or '')
  if not scope_matches(claims, claims.domain_id, claims.action, claims.rule_id, client_ip) then
    ngx.status = 403
    ngx.header.content_type = 'application/json'
    ngx.say(cjson.encode({ ok = false, error = 'scope_mismatch' }))
    return ngx.exit(403)
  end
  local difficulty = tonumber(claims.difficulty or default_difficulty) or default_difficulty
  local proof = tostring(args.pow or '')
  if difficulty <= 1 then
    if proof ~= 'browser-check' then
      ngx.status = 403
      ngx.header.content_type = 'application/json'
      ngx.say(cjson.encode({ ok = false, error = 'invalid_browser_check' }))
      return ngx.exit(403)
    end
  else
    local digest = hash_hex(tostring(args.token or '') .. ':' .. proof)
    if proof == '' or string.sub(digest, 1, difficulty) ~= proof_prefix(difficulty) then
      ngx.status = 403
      ngx.header.content_type = 'application/json'
      ngx.say(cjson.encode({ ok = false, error = 'invalid_proof' }))
      return ngx.exit(403)
    end
  end
  local clearance_token = encode_token({
    domain_id = tostring(claims.domain_id or ''),
    action = tostring(claims.action or ''),
    rule_id = tostring(claims.rule_id or ''),
    client_ip = client_ip,
    expires_at = ngx.time() + default_ttl,
    nonce = b64(tostring(ngx.now()) .. ':' .. proof),
  })
  ngx.header['Set-Cookie'] = string.format(
    '%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax',
    cookie_name,
    clearance_token,
    default_ttl
  )
  ngx.header['Location'] = same_host_path(claims.return_path)
  return ngx.exit(303)
end

function M.challenge_response(domain_id, action, rule_id, client_ip, status_code, error_code)
  local return_path = same_host_path(ngx.var.request_uri or ngx.var.uri or '/')
  local token, expires_at, difficulty = M.issue_challenge(domain_id, action, rule_id, client_ip, return_path)
  local verify_path = '/__cdnlite_challenge_verify'
  ngx.status = status_code
  ngx.header.content_type = 'text/html; charset=utf-8'
  ngx.header['Cache-Control'] = 'no-store'
  ngx.say([[<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Security check</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0f172a;color:#e5e7eb;display:grid;min-height:100vh;place-items:center}
    main{width:min(520px,calc(100vw - 32px));padding:32px;border:1px solid #334155;background:#111827}
    h1{font-size:24px;margin:0 0 12px}
    p{line-height:1.55;color:#cbd5e1}
    .bar{height:8px;background:#334155;overflow:hidden;margin-top:20px}
    .bar span{display:block;height:100%;width:45%;background:#38bdf8;animation:pulse 1.2s ease-in-out infinite}
    @keyframes pulse{50%{transform:translateX(120%)}}
  </style>
</head>
<body>
<main>
  <h1>Security check</h1>
  <p>CDNLite is checking this browser before sending traffic to the site.</p>
  <p id="status">Preparing challenge...</p>
  <div class="bar"><span></span></div>
</main>
<script>
(async function(){
  const token = ]] .. js_string(token or '') .. [[;
  const difficulty = ]] .. tostring(difficulty or default_difficulty) .. [[;
  const prefix = '0'.repeat(difficulty);
  const mode = difficulty <= 1 ? 'browser_check' : 'proof_of_work';
  const status = document.getElementById('status');
  async function submitProof(proof) {
    const body = new URLSearchParams({ token, pow: proof });
    const response = await fetch(]] .. js_string(verify_path) .. [[, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
      credentials: 'same-origin',
      redirect: 'follow'
    });
    if (response.redirected) {
      window.location.href = response.url;
      return true;
    }
    if (response.ok) {
      window.location.href = ]] .. js_string(return_path) .. [[;
      return true;
    }
    return false;
  }
  async function sha256Hex(input) {
    const data = new TextEncoder().encode(input);
    const hash = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(hash)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }
  if (mode === 'browser_check') {
    status.textContent = 'Verifying browser...';
    if (await submitProof('browser-check')) return;
    status.textContent = 'Verification failed. Refresh to try again.';
    return;
  }
  for (let i = 0; i < 5000000; i++) {
    if (i % 5000 === 0) {
      status.textContent = 'Checking browser...';
      await new Promise((resolve) => setTimeout(resolve, 0));
    }
    const proof = String(i);
    const digest = await sha256Hex(token + ':' + proof);
    if (digest.startsWith(prefix)) {
      if (await submitProof(proof)) return;
      status.textContent = 'Challenge failed. Refresh to try again.';
      return;
    }
  }
  status.textContent = 'Challenge took too long. Refresh to try again.';
})();
</script>
<noscript>JavaScript is required to complete this security check.</noscript>
</body>
</html>]])
  return ngx.exit(status_code)
end

return M
