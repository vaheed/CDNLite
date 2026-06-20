local loader = require('config_loader')
local proxy = require('proxy')
local cjson = require('cjson.safe')
local identity = require('identity')
local origin_selector = require('origin_selector')
local ip_rules = require('ip_rules')
local edge_log = require('edge_log')

local M = {}
local SECURITY_EVENT_PATH = '/var/lib/cdnlite/security-events.ndjson'

-- cjson decodes SQL NULL fields as cjson.null. Never stringify that sentinel:
-- doing so turns it into "userdata: NULL" and can accidentally classify an
-- ordinary managed WAF rule as a bot policy.
local function optional_string(value)
  if value == nil or value == cjson.null then
    return ''
  end
  return tostring(value)
end

local function append_security_event(domain_id)
  local t = tostring(ngx.ctx.security_event_type or '')
  if t == '' then
    return
  end
  local line = cjson.encode({
    ts = os.time(),
    domain_id = tostring(domain_id or ngx.ctx.domain_id or ''),
    edge_node_id = identity.get(),
    request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''),
    type = t,
    action = tostring(ngx.ctx.security_action or ''),
    rule_id = tostring(ngx.ctx.security_rule_id or ''),
    rate_limit_id = tostring(ngx.ctx.security_rate_limit_id or ''),
    limit_key_type = tostring(ngx.ctx.security_limit_key_type or ''),
    threshold = tonumber(ngx.ctx.security_threshold or 0) or 0,
    current_count = tonumber(ngx.ctx.security_current_count or 0) or 0,
    window_seconds = tonumber(ngx.ctx.security_window_seconds or 0) or 0,
    retry_after = tonumber(ngx.ctx.security_retry_after or 0) or 0,
    group_id = tostring(ngx.ctx.security_group_id or ''),
    severity = tostring(ngx.ctx.security_severity or ''),
    confidence = tostring(ngx.ctx.security_confidence or ''),
    safe_reason = tostring(ngx.ctx.security_safe_reason or ''),
    bot_class = tostring(ngx.ctx.security_bot_class or ''),
    bot_score = tonumber(ngx.ctx.security_bot_score or 0) or 0,
    bot_action = tostring(ngx.ctx.security_bot_action or ''),
    path = tostring(ngx.var.uri or '/'),
    method = tostring(ngx.req.get_method() or ''),
    client_ip = tostring(ngx.var.remote_addr or ''),
  })
  if not line then return end
  local f = io.open(SECURITY_EVENT_PATH, 'a')
  if not f then return end
  f:write(line .. '\n')
  f:close()
end

local function normalize_host(host)
  if not host then return nil end
  return string.lower(host:gsub(':%d+$', ''))
end

local function ensure_request_id()
  local reqid = ngx.var.request_id
  if reqid and reqid ~= '' then
    ngx.ctx.request_id = reqid
    return
  end
  ngx.ctx.request_id = string.format('%x-%x', math.floor(ngx.now() * 1000), ngx.worker.pid())
end

local function request_country()
  local country = ngx.var.http_x_cdnlite_country or ngx.var.http_cf_ipcountry or ''
  country = string.upper(country)
  if country == '' then
    return 'DEFAULT'
  end
  return country
end

local function match_cache_rule(cfg, host)
  local rules = cfg.cache_rules or {}
  local path = ngx.var.uri or '/'
  local best = nil
  local best_len = -1
  local has_host_rules = false
  for _, rule in ipairs(rules) do
    if rule and rule.host == host and rule.enabled and type(rule.path_prefix) == 'string' then
      has_host_rules = true
      local prefix = rule.path_prefix
      if path:sub(1, #prefix) == prefix and #prefix > best_len then
        best = rule
        best_len = #prefix
      end
    end
  end
  return best, has_host_rules
end

local function match_redirect_rule(cfg, host)
  local rules = cfg.redirects or {}
  local path = ngx.var.uri or '/'
  for _, rule in ipairs(rules) do
    if rule and rule.host == host and rule.enabled then
      if rule.managed_by == 'force_https' and ngx.var.scheme == 'http' then
        return rule
      end
      if rule.managed_by ~= 'force_https' and rule.source_path == path then
        return rule
      end
    end
  end
  return nil
end

local function split_once(input, sep)
  local i, j = string.find(input or '', sep, 1, true)
  if not i then
    return input, ''
  end
  return string.sub(input, 1, i - 1), string.sub(input, j + 1)
end

local function ip_in_cidr(ip, cidr)
  local base, prefix = split_once(cidr or '', '/')
  local n = tonumber(prefix or '')
  if not base or base == '' or not n then
    return false
  end
  local ip_bin = ngx.re.match(ip or '', [[^\d{1,3}(?:\.\d{1,3}){3}$]], 'jo')
  local base_bin = ngx.re.match(base or '', [[^\d{1,3}(?:\.\d{1,3}){3}$]], 'jo')
  if not ip_bin or not base_bin or n < 0 or n > 32 then
    return false
  end
  local function ipv4_to_u32(v)
    local a, b, c, d = string.match(v, '^(%d+)%.(%d+)%.(%d+)%.(%d+)$')
    a, b, c, d = tonumber(a), tonumber(b), tonumber(c), tonumber(d)
    if not a or a > 255 or not b or b > 255 or not c or c > 255 or not d or d > 255 then return nil end
    return a * 16777216 + b * 65536 + c * 256 + d
  end
  local ipn = ipv4_to_u32(ip)
  local basen = ipv4_to_u32(base)
  if not ipn or not basen then
    return false
  end
  local hostbits = 32 - n
  local mask = hostbits == 32 and 0 or (0xFFFFFFFF - (2 ^ hostbits - 1))
  return bit.band(ipn, mask) == bit.band(basen, mask)
end

local function waf_rule_matches(rule, path, method, client_ip, country, ua)
  local t = tostring(rule.type or '')
  local pattern = tostring(rule.pattern or '')
  if pattern == '' then
    return false
  end
  if t == 'path_contains' then return string.find(path, pattern, 1, true) ~= nil end
  if t == 'path_prefix' then return string.sub(path, 1, #pattern) == pattern end
  if t == 'user_agent_contains' then return string.find(ua, pattern, 1, true) ~= nil end
  if t == 'ip_cidr' then return ip_in_cidr(client_ip, pattern) end
  if t == 'country_is' then return string.upper(country) == string.upper(pattern) end
  if t == 'method_is' then return string.upper(method) == string.upper(pattern) end
  if t == 'header_contains' then
    local header_name, header_value = split_once(pattern, ':')
    if not header_name or header_name == '' or not header_value or header_value == '' then
      return false
    end
    local v = ngx.req.get_headers()[header_name]
    return type(v) == 'string' and string.find(v, header_value, 1, true) ~= nil
  end
  return false
end

local function apply_waf(cfg, host)
  local rules = cfg.waf_rules or {}
  local path = ngx.var.uri or '/'
  local method = ngx.req.get_method() or ''
  local client_ip = ngx.var.remote_addr or ''
  local country = request_country()
  local ua = tostring(ngx.var.http_user_agent or '')
  for _, rule in ipairs(rules) do
    if rule and rule.host == host and rule.enabled and waf_rule_matches(rule, path, method, client_ip, country, ua) then
      ngx.ctx.security_event_type = 'waf_match'
      ngx.ctx.security_rule_id = tostring(rule.id or '')
      ngx.ctx.security_action = tostring(rule.action or 'block')
      ngx.ctx.security_group_id = tostring(rule.waf_group_id or '')
      ngx.ctx.security_severity = tostring(rule.waf_severity or '')
      ngx.ctx.security_confidence = tostring(rule.waf_confidence or '')
      ngx.ctx.security_safe_reason = tostring(rule.waf_safe_reason or '')
      ngx.ctx.security_bot_class = optional_string(rule.bot_class)
      ngx.ctx.security_bot_score = tonumber(optional_string(rule.bot_score)) or 0
      ngx.ctx.security_bot_action = optional_string(rule.bot_action)
      if ngx.ctx.security_bot_action == '' then
        ngx.ctx.security_bot_action = optional_string(rule.action)
      end
      if ngx.ctx.security_bot_class ~= '' then
        ngx.ctx.security_event_type = 'bot_match'
      end
      if ngx.ctx.security_action == 'allow' then
        return true
      end
      if ngx.ctx.security_action == 'block' then
        append_security_event(nil)
        ngx.status = 403
        ngx.header.content_type = 'application/json'
        identity.apply()
        ngx.say('{"error":"blocked_by_waf","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
        return ngx.exit(403)
      end
      if ngx.ctx.security_action == 'challenge' then
        append_security_event(nil)
        ngx.status = 403
        ngx.header.content_type = 'application/json'
        identity.apply()
        ngx.say('{"error":"bot_challenge_required","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
        return ngx.exit(403)
      end
      return true
    end
  end
  return true
end

local function match_rate_limit(cfg, host)
  local rules = cfg.rate_limits or {}
  local path = ngx.var.uri or '/'
  local best = nil
  local best_len = -1
  for _, rule in ipairs(rules) do
    if rule and rule.host == host and rule.enabled and type(rule.path_prefix) == 'string' then
      local prefix = rule.path_prefix
      if path:sub(1, #prefix) == prefix and #prefix > best_len then
        best = rule
        best_len = #prefix
      end
    end
  end
  return best
end

local function request_header_value(name)
  local header_name = tostring(name or '')
  if header_name == '' then
    return ''
  end
  local var_name = 'http_' .. string.lower(string.gsub(header_name, '-', '_'))
  return tostring(ngx.var[var_name] or '')
end

local function apply_rate_limit(cfg, host, domain_id)
  local rule = match_rate_limit(cfg, host)
  if not rule then
    return true
  end
  local rpm = tonumber(rule.requests_per_minute or 0) or 0
  if rpm <= 0 then
    return true
  end

  local key_type = tostring(rule.key_type or 'ip')
  local client_ip = ngx.var.remote_addr or ''
  local key = client_ip
  if key_type == 'ip_path' then
    key = client_ip .. '|' .. (ngx.var.uri or '/')
  elseif key_type == 'header' or key_type == 'header_path' then
    local header_value = request_header_value(rule.key_header_name)
    if header_value ~= '' then
      key = 'h|' .. tostring(rule.key_header_name or '') .. '|' .. header_value
      if key_type == 'header_path' then
        key = key .. '|' .. (ngx.var.uri or '/')
      end
    end
  end
  local bucket = tostring(math.floor(ngx.now() / 60))
  local counter_key = tostring(domain_id) .. '|' .. tostring(rule.id or '') .. '|' .. key .. '|' .. bucket
  local dict = ngx.shared.cdnlite_limits
  if not dict then
    return true
  end
  local current, err = dict:incr(counter_key, 1, 0, 61)
  if not current then
    ngx.log(ngx.ERR, 'rate_limit_counter_error: ', tostring(err or 'unknown'))
    return true
  end
  if current <= rpm then
    return true
  end

  ngx.ctx.security_event_type = 'rate_limited'
  ngx.ctx.security_rule_id = tostring(rule.id or '')
  ngx.ctx.security_rate_limit_id = tostring(rule.id or '')
  ngx.ctx.security_action = tostring(rule.action or 'block')
  ngx.ctx.security_limit_key_type = key_type
  ngx.ctx.security_threshold = rpm
  ngx.ctx.security_current_count = current
  ngx.ctx.security_window_seconds = 60
  ngx.ctx.security_retry_after = 60
  if ngx.ctx.security_action == 'block' then
    append_security_event(domain_id)
    edge_log.warn('rate_limited', { domain_id = tostring(domain_id or ''), rule_id = tostring(rule.id or '') })
    ngx.status = 429
    ngx.header.content_type = 'application/json'
    identity.apply()
    ngx.header['Retry-After'] = '60'
    ngx.say('{"error":"rate_limited","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
    return ngx.exit(429)
  elseif ngx.ctx.security_action == 'challenge' then
    append_security_event(domain_id)
    edge_log.warn('rate_limit_challenge', { domain_id = tostring(domain_id or ''), rule_id = tostring(rule.id or '') })
    ngx.status = 429
    ngx.header.content_type = 'application/json'
    identity.apply()
    ngx.header['Retry-After'] = '60'
    ngx.say('{"error":"challenge_required","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
    return ngx.exit(429)
  end
  return true
end

function M.handle()
  ensure_request_id()
  local cfg = loader.load()
  local host = normalize_host(ngx.var.host)
  if not host then
    edge_log.warn('router_error', { router_error = 'missing_host' })
    return false, 'missing_host'
  end

  local domain = cfg.hosts[host]
  if not domain then
    edge_log.warn('router_error', { router_error = 'domain_not_configured' })
    return false, 'domain_not_configured'
  end
  ngx.ctx.domain_id = domain.domain_id
  ngx.ctx.header_rules = domain.header_rules or {}

  local ip_ok = ip_rules.apply(domain)
  if not ip_ok then
    edge_log.warn('router_error', { domain_id = tostring(domain.domain_id or ''), router_error = 'ip_access_blocked' })
    return false, 'ip_access_blocked'
  end

  local redirect = match_redirect_rule(cfg, host)
  if redirect then
    local target = tostring(redirect.target_url or '')
    if redirect.managed_by == 'force_https' then
      target = target .. tostring(ngx.var.request_uri or ngx.var.uri or '/')
    end
    ngx.header['Location'] = target
    ngx.header['X-CDNLITE-Rule'] = 'redirect'
    identity.apply()
    ngx.header['X-CDNLITE-Request-Id'] = tostring(ngx.ctx.request_id or ngx.var.request_id or '')
    return ngx.exit(tonumber(redirect.status_code) or 302)
  end

  local waf_ok = apply_waf(cfg, host)
  if not waf_ok then
    return false, 'waf_blocked'
  end

  local rate_limit_ok = apply_rate_limit(cfg, host, domain.domain_id)
  if not rate_limit_ok then
    return false, 'rate_limited'
  end
  local country = request_country()
  local upstream, origin_meta = origin_selector.select(domain, country, tostring(ngx.ctx.request_id or ngx.var.request_id or ngx.var.uri or ''))
  if not upstream then
    edge_log.error('router_error', { domain_id = tostring(domain.domain_id or ''), router_error = tostring(origin_meta or 'no_healthy_origin') })
    return false, origin_meta or 'no_healthy_origin'
  end
  ngx.ctx.upstream = upstream
  ngx.ctx.origin = origin_meta or {}
  ngx.ctx.origin_scheme = ngx.ctx.origin.scheme
  ngx.ctx.origin_pool_size = #(domain.origins or {})
  ngx.ctx.cache_rule, ngx.ctx.cache_rules_enabled = match_cache_rule(cfg, host)
  ngx.ctx.cache_settings = domain.cache or {}
  edge_log.info('origin_selected', {
    domain_id = tostring(domain.domain_id or ''),
    origin_id = tostring(ngx.ctx.origin.id or ''),
    origin_source = tostring(ngx.ctx.origin.source or ''),
    origin_scheme = tostring(ngx.ctx.origin.scheme or ''),
    origin_host = tostring(ngx.ctx.origin.host or ''),
    origin_port = tostring(ngx.ctx.origin.port or ''),
    origin_pool_size = tostring(ngx.ctx.origin_pool_size or ''),
  })
  return proxy.forward(domain)
end

return M
