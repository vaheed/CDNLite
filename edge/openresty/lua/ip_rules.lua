local M = {}
local identity = require('identity')
local telemetry_queue = require('telemetry_queue')

local function append_security_event(domain, rule_id, action)
  telemetry_queue.write_now('security_events', {
    ts = os.time(),
    domain_id = tostring(domain and domain.domain_id or ngx.ctx.domain_id or ''),
    edge_node_id = identity.get(),
    request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''),
    type = 'ip_access',
    action = tostring(action or ''),
    rule_id = tostring(rule_id or ''),
    path = tostring(ngx.var.uri or '/'),
    method = tostring(ngx.req.get_method() or ''),
    client_ip = tostring(ngx.var.remote_addr or ''),
  })
end

local function split_once(input, sep)
  local i, j = string.find(input or '', sep, 1, true)
  if not i then
    return input, ''
  end
  return string.sub(input, 1, i - 1), string.sub(input, j + 1)
end

local function ipv4_to_u32(v)
  local a, b, c, d = string.match(v or '', '^(%d+)%.(%d+)%.(%d+)%.(%d+)$')
  a, b, c, d = tonumber(a), tonumber(b), tonumber(c), tonumber(d)
  if not a or a > 255 or not b or b > 255 or not c or c > 255 or not d or d > 255 then return nil end
  return a * 16777216 + b * 65536 + c * 256 + d
end

function M.match(ip, cidr)
  local base, prefix = split_once(cidr or '', '/')
  local n = tonumber(prefix or '')
  if not base or base == '' or not n or n < 0 or n > 32 then
    return false
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

function M.apply(domain)
  local rules = domain and domain.ip_rules or {}
  if type(rules) ~= 'table' or #rules == 0 then
    return true
  end
  local client_ip = ngx.var.remote_addr or ''
  local has_allow = false
  local allowed = false
  for _, rule in ipairs(rules) do
    if rule and rule.enabled and tostring(rule.rule_type or '') == 'allow' then
      has_allow = true
      if M.match(client_ip, tostring(rule.cidr or '')) then
        allowed = true
      end
    end
  end
  for _, rule in ipairs(rules) do
    if rule and rule.enabled and tostring(rule.rule_type or '') == 'block' and M.match(client_ip, tostring(rule.cidr or '')) then
      ngx.ctx.security_event_type = 'ip_access'
      ngx.ctx.security_rule_id = tostring(rule.id or '')
      ngx.ctx.security_action = 'block'
      append_security_event(domain, rule.id, 'block')
      ngx.status = 403
      ngx.header.content_type = 'application/json'
      identity.apply()
      ngx.say('{"error":"blocked_by_ip_rule","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
      return ngx.exit(403)
    end
  end
  if has_allow and not allowed then
    ngx.ctx.security_event_type = 'ip_access'
    ngx.ctx.security_action = 'deny'
    append_security_event(domain, '', 'deny')
    ngx.status = 403
    ngx.header.content_type = 'application/json'
    identity.apply()
    ngx.say('{"error":"not_allowed_by_ip_rule","request_id":"' .. tostring(ngx.ctx.request_id or '') .. '"}')
    return ngx.exit(403)
  end
  return true
end

return M
