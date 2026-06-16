local M = {}
local identity = require('identity')
local edge_log = require('edge_log')

local function header_has_cache_directive(value)
  if not value then
    return false
  end

  value = string.lower(value)
  return value:find('no-cache', 1, true) ~= nil or value:find('no-store', 1, true) ~= nil
end

function M.forward(domain)
  local upstream = ngx.ctx.upstream or domain.upstream
  if not upstream then
    return false, 'missing_upstream'
  end

  local cache_bypass = false
  local cache_no_store = false
  local cache_settings = ngx.ctx.cache_settings or {}
  local method = ngx.req.get_method()
  local cache_rule = ngx.ctx.cache_rule
  local edge_ttl = nil
  if cache_rule and tonumber(cache_rule.ttl_seconds) and tonumber(cache_rule.ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_rule.ttl_seconds))
  elseif cache_settings.enabled ~= false and tonumber(cache_settings.default_edge_ttl_seconds) and tonumber(cache_settings.default_edge_ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_settings.default_edge_ttl_seconds))
  end

  if method ~= 'GET' and method ~= 'HEAD' then
    cache_bypass = true
    cache_no_store = true
  end

  if cache_settings.enabled == false or not edge_ttl then
    cache_bypass = true
    cache_no_store = true
  end

  if cache_settings.cache_authorized_requests ~= true and ngx.var.http_authorization and ngx.var.http_authorization ~= '' then
    cache_bypass = true
    cache_no_store = true
  end

  if header_has_cache_directive(ngx.var.http_cache_control) then
    cache_bypass = true
    cache_no_store = true
  end

  ngx.var.target_upstream = upstream
  ngx.var.target_backup_upstream = tostring(ngx.ctx.backup_upstream or '')
  ngx.var.target_domain_id = tostring(domain.domain_id or '')
  ngx.var.target_origin_host_header = tostring((ngx.ctx.origin or {}).host_header or ngx.var.host or '')
  ngx.var.target_origin_sni = tostring((ngx.ctx.origin or {}).sni or (ngx.ctx.origin or {}).host or ngx.var.host or '')
  ngx.var.target_origin_id = tostring((ngx.ctx.origin or {}).id or '')
  ngx.var.target_origin_host = tostring((ngx.ctx.origin or {}).host or '')
  ngx.var.target_origin_tls_verify = tostring((ngx.ctx.origin or {}).tls_verify or 'verify')
  ngx.var.target_backup_origin_host_header = tostring((ngx.ctx.backup_origin or {}).host_header or ngx.var.target_origin_host_header or '')
  ngx.var.target_backup_origin_sni = tostring((ngx.ctx.backup_origin or {}).sni or ngx.var.target_origin_sni or '')
  ngx.var.target_backup_origin_id = tostring((ngx.ctx.backup_origin or {}).id or '')
  ngx.var.target_backup_origin_host = tostring((ngx.ctx.backup_origin or {}).host or '')
  ngx.var.target_backup_origin_tls_verify = tostring((ngx.ctx.backup_origin or {}).tls_verify or 'verify')
  ngx.var.cdnlite_cache_bypass = cache_bypass and '1' or '0'
  ngx.var.cdnlite_cache_no_store = cache_no_store and '1' or '0'
  if edge_ttl and not cache_no_store then
    ngx.header['X-Accel-Expires'] = tostring(edge_ttl)
  end
  identity.apply()
  ngx.header['X-CDNLITE-Domain'] = tostring(domain.domain_id)
  ngx.header['X-CDNLITE-Origin'] = 'primary'
  ngx.header['X-CDNLITE-Request-Id'] = tostring(ngx.ctx.request_id or ngx.var.request_id or '')
  edge_log.debug('proxy_forward', {
    domain_id = tostring(domain.domain_id or ''),
    origin_id = tostring(ngx.var.target_origin_id or ''),
    origin_role = tostring((ngx.ctx.origin or {}).role or 'primary'),
    upstream = upstream,
  })
  return true
end

return M
