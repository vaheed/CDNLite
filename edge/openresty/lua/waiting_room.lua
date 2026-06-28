local cjson = require('cjson.safe')
local identity = require('identity')
local edge_log = require('edge_log')

local M = {}
local ticket_cookie = '__cdnlite_queue_ticket'
local admit_cookie = '__cdnlite_admission'

local function secret()
  local value = os.getenv('CDNLITE_EDGE_WAITING_ROOM_SECRET') or os.getenv('CDNLITE_EDGE_CLEARANCE_SECRET') or ''
  return value ~= '' and value or 'cdnlite-dev-waiting-room-secret'
end

local function b64(value)
  return (ngx.encode_base64(value or ''):gsub('+', '-'):gsub('/', '_'):gsub('=', ''))
end

local function unb64(value)
  local padded = tostring(value or ''):gsub('-', '+'):gsub('_', '/')
  local remainder = #padded % 4
  if remainder > 0 then padded = padded .. string.rep('=', 4 - remainder) end
  return ngx.decode_base64(padded)
end

local function sign(payload)
  return b64(ngx.hmac_sha1(secret(), payload))
end

local function encode_token(claims)
  local json = cjson.encode(claims)
  if not json then return nil end
  local payload = b64(json)
  return payload .. '.' .. sign(payload)
end

local function decode_token(token)
  local payload, signature = tostring(token or ''):match('^([A-Za-z0-9_-]+)%.([A-Za-z0-9_-]+)$')
  if not payload or not signature or sign(payload) ~= signature then return nil end
  local claims = cjson.decode(unb64(payload) or '')
  if type(claims) ~= 'table' or tonumber(claims.expires_at or 0) <= ngx.time() then return nil end
  return claims
end

local function html_escape(value)
  return tostring(value or ''):gsub('&', '&amp;'):gsub('<', '&lt;'):gsub('>', '&gt;'):gsub('"', '&quot;'):gsub("'", '&#39;')
end

local function same_host_path(value)
  local path = tostring(value or '/')
  if path == '' or path:sub(1, 1) ~= '/' or path:sub(1, 2) == '//' or path:find('[\r\n]') then return '/' end
  if path:sub(1, 29) == '/.well-known/cdnlite/queue' then return '/' end
  return path
end

local function key(domain_id, suffix)
  return 'wr|' .. tostring(domain_id or '') .. '|' .. suffix
end

local function policy_for_host()
  local loader = require('config_loader')
  local cfg = loader.load()
  local host = string.lower(tostring(ngx.var.host or ''):gsub(':%d+$', ''))
  local domain = cfg.hosts and cfg.hosts[host] or nil
  if not domain then return nil, nil end
  return domain.waiting_room, domain
end

local function overload_active(policy)
  if not policy or policy.enabled ~= true then return false end
  local state = tostring(policy.state or '')
  if state == 'manual_emergency' or state == 'overloaded' or state == 'entering_overload' then return true end
  if tostring(policy.mode or '') == 'manual' and tonumber(policy.manual_override_until or 0) > ngx.time() then return true end
  if tostring(policy.mode or '') == 'automatic' then
    local dict = ngx.shared.cdnlite_waiting_room
    local domain_id = tostring(policy.domain_id or '')
    local second = tostring(ngx.time())
    local rps = dict and dict:incr(key(domain_id, 'rps|' .. second), 1, 0, 2) or 0
    if rps and rps > (tonumber(policy.rps_threshold or 100) or 100) then
      if dict then dict:set(key(domain_id, 'state'), 'overloaded', tonumber(policy.minimum_state_seconds or 60) or 60) end
      return true
    end
    if dict and dict:get(key(domain_id, 'state')) == 'overloaded' then
      return true
    end
  end
  return false
end

local function client_ip()
  return tostring(ngx.var.remote_addr or '')
end

local function header_has_cache_directive(value)
  if not value then
    return false
  end
  value = string.lower(value)
  return value:find('no-cache', 1, true) ~= nil or value:find('no-store', 1, true) ~= nil
end

local function has_logged_in_cookie(value)
  if not value then return false end
  value = string.lower(value)
  return value:find('%f[%w]session[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]auth[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]wordpress_logged_in[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]laravel_session%s*=') ~= nil
end

local static_extensions = {
  css = true, js = true, png = true, jpg = true, jpeg = true, gif = true,
  svg = true, webp = true, ico = true, woff = true, woff2 = true, ttf = true,
  mp4 = true, pdf = true,
}

local function is_static_asset(path)
  local extension = string.lower((path or ''):match('%.([%w]+)$') or '')
  return static_extensions[extension] == true
end

local function cache_candidate()
  local method = ngx.req.get_method()
  if method ~= 'GET' and method ~= 'HEAD' then
    return false
  end
  local cache_settings = ngx.ctx.cache_settings or {}
  if cache_settings.enabled == false then
    return false
  end
  local cache_rule = ngx.ctx.cache_rule
  local cache_rules_enabled = ngx.ctx.cache_rules_enabled == true
  local edge_ttl = nil
  if cache_rule and tonumber(cache_rule.ttl_seconds) and tonumber(cache_rule.ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_rule.ttl_seconds))
  elseif not cache_rules_enabled and tonumber(cache_settings.default_edge_ttl_seconds) and tonumber(cache_settings.default_edge_ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_settings.default_edge_ttl_seconds))
  end
  if not edge_ttl and is_static_asset(ngx.var.uri) and cache_settings.static_asset_cache_enabled == true and tonumber(cache_settings.default_edge_ttl_seconds) and tonumber(cache_settings.default_edge_ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_settings.default_edge_ttl_seconds))
  end
  if not edge_ttl then
    return false
  end
  if cache_settings.cache_authorized_requests ~= true and ngx.var.http_authorization and ngx.var.http_authorization ~= '' then
    return false
  end
  if cache_settings.bypass_logged_in_users ~= false and has_logged_in_cookie(ngx.var.http_cookie) then
    return false
  end
  if header_has_cache_directive(ngx.var.http_cache_control) then
    return false
  end
  return true
end

local function has_admission(policy, domain_id)
  local claims = decode_token(ngx.var['cookie_' .. admit_cookie] or '')
  return claims and tostring(claims.domain_id or '') == tostring(domain_id or '') and tostring(claims.client_ip or '') == client_ip()
end

local function issue_admission(policy, domain_id)
  local ttl = tonumber(policy.admission_ttl_seconds or 900) or 900
  local token = encode_token({ token_type = 'admission', domain_id = tostring(domain_id), client_ip = client_ip(), expires_at = ngx.time() + ttl })
  if token then
    ngx.header['Set-Cookie'] = string.format('%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Lax', admit_cookie, token, ttl)
  end
end

local function admit_budget(policy, domain_id)
  local dict = ngx.shared.cdnlite_waiting_room
  if not dict then return true end
  local minute = tostring(math.floor(ngx.time() / 60))
  local budget_key = key(domain_id, 'admitted|' .. minute)
  local current = dict:incr(budget_key, 1, 0, 61)
  return current and current <= (tonumber(policy.admission_rate_per_minute or 60) or 60)
end

local function issue_ticket(policy, domain_id, return_path)
  local dict = ngx.shared.cdnlite_waiting_room
  local queue_limit = tonumber(policy.queue_limit or 1000) or 1000
  local ttl = tonumber(policy.ticket_ttl_seconds or 300) or 300
  local count = dict and (dict:incr(key(domain_id, 'waiting'), 1, 0, ttl) or queue_limit + 1) or 1
  if count > queue_limit then
    if dict then dict:incr(key(domain_id, 'rejected'), 1, 0, 3600) end
    return nil, 'queue_full'
  end
  local token = encode_token({
    token_type = 'queue_ticket', domain_id = tostring(domain_id), client_ip = client_ip(),
    issued_at = ngx.time(), expires_at = ngx.time() + ttl, return_path = same_host_path(return_path),
    position_hint = count,
  })
  if token then
    ngx.header['Set-Cookie'] = string.format('%s=%s; Max-Age=%d; Path=/.well-known/cdnlite/queue; HttpOnly; SameSite=Lax', ticket_cookie, token, ttl)
  end
  return token, nil, count
end

local function json_response(status, body, retry_after)
  ngx.status = status
  ngx.header.content_type = 'application/json'
  ngx.header['Cache-Control'] = 'no-store'
  ngx.header['X-Robots-Tag'] = 'noindex, nofollow'
  ngx.header['X-CDNLITE-Request-Id'] = tostring(ngx.ctx.request_id or ngx.var.request_id or '')
  if retry_after then ngx.header['Retry-After'] = tostring(retry_after) end
  identity.apply()
  ngx.say(cjson.encode(body))
  return ngx.exit(status)
end

function M.apply(policy, domain)
  if not overload_active(policy) then return true end
  local domain_id = tostring(domain.domain_id or '')
  local method = ngx.req.get_method()
  if cache_candidate() then
    ngx.ctx.waiting_room_cache_candidate = true
    return true
  end
  if has_admission(policy, domain_id) then return true end
  if method ~= 'GET' and method ~= 'HEAD' then
    return json_response(429, { error = 'waiting_room_required', request_id = tostring(ngx.ctx.request_id or ''), estimated_wait_seconds = tonumber(policy.status_poll_seconds or 5) or 5 }, tonumber(policy.status_poll_seconds or 5) or 5)
  end
  if admit_budget(policy, domain_id) then
    issue_admission(policy, domain_id)
    return true
  end
  local ticket, err = issue_ticket(policy, domain_id, ngx.var.request_uri or '/')
  if not ticket then
    return json_response(503, { error = err or 'waiting_room_unavailable', request_id = tostring(ngx.ctx.request_id or '') }, tonumber(policy.status_poll_seconds or 5) or 5)
  end
  local url = '/.well-known/cdnlite/queue?return=' .. ngx.escape_uri(same_host_path(ngx.var.request_uri or '/'))
  ngx.header['Location'] = url
  ngx.header['Cache-Control'] = 'no-store'
  edge_log.warn('waiting_room_redirect', { domain_id = domain_id })
  return ngx.exit(302)
end

function M.mark_origin(domain)
  local dict = ngx.shared.cdnlite_waiting_room
  if not dict or not domain then return end
  if ngx.ctx.waiting_room_cache_candidate == true then return end
  local domain_id = tostring(domain.domain_id or '')
  ngx.ctx.waiting_room_domain_id = domain_id
  ngx.ctx.waiting_room_origin_marked = true
  dict:incr(key(domain_id, 'active_origin'), 1, 0, 300)
  dict:incr(key(domain_id, 'origin_bound'), 1, 0, 3600)
end

function M.on_log()
  local dict = ngx.shared.cdnlite_waiting_room
  if not dict or ngx.ctx.waiting_room_origin_marked ~= true then return end
  local domain_id = tostring(ngx.ctx.waiting_room_domain_id or '')
  if domain_id ~= '' then
    dict:incr(key(domain_id, 'active_origin'), -1, 0, 300)
  end
end

function M.queue_status()
  local policy, domain = policy_for_host()
  if not policy or not domain then
    return json_response(404, { error = 'domain_not_configured', request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or '') })
  end
  local domain_id = tostring(domain.domain_id or '')
  local poll = tonumber(policy.status_poll_seconds or 5) or 5
  local dict = ngx.shared.cdnlite_waiting_room
  local counters = {
    waiting = dict and (tonumber(dict:get(key(domain_id, 'waiting')) or 0) or 0) or 0,
    active_origin = dict and (tonumber(dict:get(key(domain_id, 'active_origin')) or 0) or 0) or 0,
    origin_bound = dict and (tonumber(dict:get(key(domain_id, 'origin_bound')) or 0) or 0) or 0,
    rejected = dict and (tonumber(dict:get(key(domain_id, 'rejected')) or 0) or 0) or 0,
  }
  if admit_budget(policy, domain_id) then
    issue_admission(policy, domain_id)
    return json_response(200, { ok = true, admitted = true, redirect_to = same_host_path(ngx.var.arg_return or '/'), counters = counters })
  end
  return json_response(200, { ok = true, admitted = false, retry_after = poll, jitter_seconds = tonumber(policy.jitter_seconds or 4) or 4, request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''), counters = counters }, poll)
end

function M.queue_page()
  local policy, domain = policy_for_host()
  if not policy or not domain then return json_response(404, { error = 'domain_not_configured' }) end
  issue_ticket(policy, tostring(domain.domain_id or ''), ngx.var.arg_return or '/')
  local poll = tonumber(policy.status_poll_seconds or 5) or 5
  local jitter = tonumber(policy.jitter_seconds or 4) or 4
  ngx.header['Cache-Control'] = 'no-store'
  ngx.header['X-Robots-Tag'] = 'noindex, nofollow'
  ngx.header['Content-Security-Policy'] = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'"
  identity.apply()
  ngx.say('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' .. html_escape(policy.waiting_room_title or 'Traffic is high') .. '</title><style>body{font-family:system-ui,sans-serif;margin:0;display:grid;min-height:100vh;place-items:center;background:#f8fafc;color:#111827}.box{max-width:40rem;padding:2rem}code{font-size:.875rem}</style></head><body><main class="box" role="status" aria-live="polite"><h1>' .. html_escape(policy.waiting_room_title or 'Traffic is high') .. '</h1><p>' .. html_escape(policy.waiting_room_message or '') .. '</p><p id="s">Checking for admission...</p><p><code>Request ID: ' .. html_escape(ngx.ctx.request_id or ngx.var.request_id or '') .. '</code></p></main><script>const r=' .. cjson.encode(same_host_path(ngx.var.arg_return or '/')) .. ';function p(){fetch("/.well-known/cdnlite/queue/status?return="+encodeURIComponent(r),{cache:"no-store"}).then(x=>x.json()).then(j=>{if(j.admitted){location.assign(j.redirect_to||r);return}let n=(j.retry_after||' .. poll .. ')+Math.floor(Math.random()*' .. (jitter + 1) .. ');document.getElementById("s").textContent="Estimated wait: "+n+" seconds";setTimeout(p,n*1000)}).catch(()=>setTimeout(p,' .. poll * 1000 .. '))}setTimeout(p,1000)</script></body></html>')
end

return M
