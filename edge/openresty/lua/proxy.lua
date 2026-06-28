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

local function list_contains(list, needle)
  if type(list) ~= 'table' then return false end
  needle = string.lower(tostring(needle or ''))
  for _, value in ipairs(list) do
    if string.lower(tostring(value)) == needle then return true end
  end
  return false
end

local function request_has_bypass_header(headers)
  if type(headers) ~= 'table' then return nil end
  for _, name in ipairs(headers) do
    local value = ngx.req.get_headers()[tostring(name):lower()]
    if value and tostring(value) ~= '' then return tostring(name):lower() end
  end
  return nil
end

local function cache_key_part(name, value)
  value = tostring(value or '')
  return name .. '=' .. ngx.escape_uri(value)
end

local function build_cache_key(domain, cache_settings, cache_rule, static_asset)
  local dimensions = cache_settings.cache_key_dimensions or {}
  local parts = {}
  if dimensions.scheme ~= false then table.insert(parts, cache_key_part('scheme', ngx.var.scheme)) end
  if dimensions.host ~= false then table.insert(parts, cache_key_part('host', string.lower(ngx.var.host or ''))) end
  if dimensions.domain_id ~= false then table.insert(parts, cache_key_part('domain', domain.domain_id)) end
  if dimensions.path ~= false then table.insert(parts, cache_key_part('path', ngx.var.uri or '/')) end

  local query_mode = dimensions.query or cache_settings.cache_query_string_mode or 'include_all'
  if static_asset and cache_settings.ignore_query_strings_for_static == true then query_mode = 'ignore' end
  if query_mode == 'include_all' then table.insert(parts, cache_key_part('query', ngx.var.args or '')) end

  local headers = dimensions.headers or cache_settings.vary_headers or {'accept-encoding'}
  for _, header in ipairs(headers) do
    local header_name = string.lower(tostring(header))
    if header_name ~= 'authorization' and header_name ~= 'cookie' then
      table.insert(parts, cache_key_part('h.' .. header_name, ngx.req.get_headers()[header_name] or ''))
    end
  end
  if dimensions.country == true then table.insert(parts, cache_key_part('country', geoip.request_country())) end
  if dimensions.language == true then table.insert(parts, cache_key_part('lang', ngx.var.http_accept_language or '')) end
  if dimensions.rule_version ~= false then table.insert(parts, cache_key_part('rule', (cache_rule and cache_rule.id) or 'default')) end
  return table.concat(parts, '|'), table.concat(parts, '; ')
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
  local bypass_reason = nil
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
    bypass_reason = 'method'
  end

  if type(cache_settings.cache_methods) == 'table' and not list_contains(cache_settings.cache_methods, method) then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or 'method_policy'
  end

  if cache_settings.enabled == false or not edge_ttl then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or 'disabled_or_no_ttl'
  end

  if cache_settings.cache_authorized_requests ~= true and ngx.var.http_authorization and ngx.var.http_authorization ~= '' then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or 'authorization'
  end

  if cache_settings.bypass_logged_in_users ~= false and has_logged_in_cookie(ngx.var.http_cookie) then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or 'cookie'
  end

  local bypass_header = request_has_bypass_header(cache_settings.bypass_headers)
  if bypass_header and bypass_header ~= 'authorization' then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or ('header:' .. bypass_header)
  end

  if header_has_cache_directive(ngx.var.http_cache_control) then
    cache_bypass = true
    cache_no_store = true
    bypass_reason = bypass_reason or 'request_cache_control'
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
  local cache_key, cache_debug = build_cache_key(domain, cache_settings, cache_rule, static_asset)
  ngx.var.cdnlite_cache_key = cache_key
  ngx.var.cdnlite_cache_debug = cache_debug
  ngx.var.cdnlite_cache_bypass_reason = bypass_reason or ''
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
