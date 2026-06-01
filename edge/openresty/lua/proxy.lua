local M = {}

local function header_has_cache_directive(value)
  if not value then
    return false
  end

  value = string.lower(value)
  return value:find('no-cache', 1, true) ~= nil or value:find('no-store', 1, true) ~= nil
end

function M.forward(site)
  local upstream = ngx.ctx.upstream or site.upstream
  if not upstream then
    return false, 'missing_upstream'
  end

  local cache_bypass = false
  local cache_no_store = false
  local method = ngx.req.get_method()
  local cache_rule = ngx.ctx.cache_rule
  local rule_ttl = nil
  if cache_rule and tonumber(cache_rule.ttl_seconds) and tonumber(cache_rule.ttl_seconds) > 0 then
    rule_ttl = math.floor(tonumber(cache_rule.ttl_seconds))
  end

  if method ~= 'GET' and method ~= 'HEAD' then
    cache_bypass = true
    cache_no_store = true
  end

  if not rule_ttl then
    cache_bypass = true
    cache_no_store = true
  end

  if ngx.var.http_authorization and ngx.var.http_authorization ~= '' then
    cache_bypass = true
    cache_no_store = true
  end

  if header_has_cache_directive(ngx.var.http_cache_control) then
    cache_bypass = true
    cache_no_store = true
  end

  ngx.var.target_upstream = upstream
  ngx.var.cdnlite_cache_bypass = cache_bypass and '1' or '0'
  ngx.var.cdnlite_cache_no_store = cache_no_store and '1' or '0'
  if rule_ttl and not cache_no_store then
    ngx.header['X-Accel-Expires'] = tostring(rule_ttl)
  else
    ngx.header['X-Accel-Expires'] = '0'
  end
  ngx.header['X-CDNLITE-Edge'] = os.getenv('EDGE_ID') or 'edge-local-1'
  ngx.header['X-CDNLITE-Site'] = tostring(site.site_id)
  ngx.header['X-CDNLITE-Request-Id'] = tostring(ngx.ctx.request_id or ngx.var.request_id or '')
  return true
end

return M
