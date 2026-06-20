local M = {}
local identity = require('identity')
local edge_log = require('edge_log')
local cjson = require('cjson.safe')
local geoip = require('geoip')

local function header_has_cache_directive(value)
  if not value then
    return false
  end

  value = string.lower(value)
  return value:find('no-cache', 1, true) ~= nil or value:find('no-store', 1, true) ~= nil
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

local function has_logged_in_cookie(value)
  if not value then return false end
  value = string.lower(value)
  -- These cookie names cover the common session defaults without treating every
  -- harmless preference cookie as private content.
  return value:find('%f[%w]session[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]auth[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]wordpress_logged_in[%w_%-]*%s*=') ~= nil
    or value:find('%f[%w]laravel_session%s*=') ~= nil
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
  local cache_rules_enabled = ngx.ctx.cache_rules_enabled == true
  local static_asset = is_static_asset(ngx.var.uri)
  local edge_ttl = nil
  if cache_rule and tonumber(cache_rule.ttl_seconds) and tonumber(cache_rule.ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_rule.ttl_seconds))
  elseif not cache_rules_enabled and cache_settings.enabled ~= false and tonumber(cache_settings.default_edge_ttl_seconds) and tonumber(cache_settings.default_edge_ttl_seconds) > 0 then
    edge_ttl = math.floor(tonumber(cache_settings.default_edge_ttl_seconds))
  end

  if not edge_ttl and static_asset and cache_settings.static_asset_cache_enabled == true and tonumber(cache_settings.default_edge_ttl_seconds) and tonumber(cache_settings.default_edge_ttl_seconds) > 0 then
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

  if cache_settings.bypass_logged_in_users ~= false and has_logged_in_cookie(ngx.var.http_cookie) then
    cache_bypass = true
    cache_no_store = true
  end

  if header_has_cache_directive(ngx.var.http_cache_control) then
    cache_bypass = true
    cache_no_store = true
  end

  ngx.var.target_upstream = upstream
  ngx.var.target_domain_id = tostring(domain.domain_id or '')
  ngx.var.target_origin_host_header = tostring((ngx.ctx.origin or {}).host_header or ngx.var.host or '')
  ngx.var.target_origin_sni = tostring((ngx.ctx.origin or {}).sni or (ngx.ctx.origin or {}).host or ngx.var.host or '')
  ngx.var.target_origin_id = tostring((ngx.ctx.origin or {}).id or '')
  ngx.var.target_origin_host = tostring((ngx.ctx.origin or {}).host or '')
  ngx.var.target_origin_tls_verify = tostring((ngx.ctx.origin or {}).tls_verify or 'ignore')
  -- Keep the routing context on the request itself so the error page can
  -- recover it even when an internal redirect clears Lua state.
  ngx.req.set_header('X-CDNLite-Domain-Id', tostring(domain.domain_id or ''))
  ngx.req.set_header('X-CDNLite-Origin-Id', tostring((ngx.ctx.origin or {}).id or ''))
  ngx.req.set_header('X-CDNLite-Origin-Host', tostring((ngx.ctx.origin or {}).host or ''))
  ngx.req.set_header('X-CDNLite-Origin-Role', tostring((ngx.ctx.origin or {}).role or 'origin'))
  ngx.req.set_header('X-CDNLite-Origin-Tls-Verify', tostring((ngx.ctx.origin or {}).tls_verify or 'ignore'))
  local request_context = cjson.encode({
    domain_id = tostring(domain.domain_id or ''),
    origin = {
      id = tostring((ngx.ctx.origin or {}).id or ''),
      host = tostring((ngx.ctx.origin or {}).host or ''),
      role = tostring((ngx.ctx.origin or {}).role or 'origin'),
      tls_verify = tostring((ngx.ctx.origin or {}).tls_verify or 'ignore'),
    },
  })
  if request_context then
    local dict = ngx.shared.cdnlite_request_context
    if dict and ngx.ctx.request_id and ngx.ctx.request_id ~= '' then
      dict:set(tostring(ngx.ctx.request_id), request_context, 60)
    end
  end
  ngx.var.cdnlite_cache_bypass = cache_bypass and '1' or '0'
  ngx.var.cdnlite_cache_no_store = cache_no_store and '1' or '0'
  local cache_uri = ngx.var.request_uri or ngx.var.uri or '/'
  if static_asset and cache_settings.ignore_query_strings_for_static == true then
    cache_uri = ngx.var.uri or '/'
  end
  ngx.var.cdnlite_cache_key = table.concat({ngx.var.scheme or '', ngx.var.host or '', cache_uri, ngx.var.http_accept_encoding or '', geoip.request_country()}, '|')
  if edge_ttl and not cache_no_store then
    ngx.header['X-Accel-Expires'] = tostring(edge_ttl)
  end
  identity.apply()
  ngx.header['X-CDNLITE-Domain'] = tostring(domain.domain_id)
  ngx.header['X-CDNLITE-Origin'] = 'origin'
  ngx.header['X-CDNLITE-Request-Id'] = tostring(ngx.ctx.request_id or ngx.var.request_id or '')
  edge_log.debug('proxy_forward', {
    domain_id = tostring(domain.domain_id or ''),
    origin_id = tostring(ngx.var.target_origin_id or ''),
    origin_role = tostring((ngx.ctx.origin or {}).role or 'origin'),
    upstream = upstream,
  })
  return true
end

return M
