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

  if method ~= 'GET' and method ~= 'HEAD' then
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
  ngx.header['X-CDNLITE-Edge'] = 'openresty'
  ngx.header['X-CDNLITE-Site'] = tostring(site.site_id)
  return true
end

return M
