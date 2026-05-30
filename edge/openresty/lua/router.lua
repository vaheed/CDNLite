local loader = require('config_loader')
local proxy = require('proxy')

local M = {}

local function normalize_host(host)
  if not host then return nil end
  return string.lower(host:gsub(':%d+$', ''))
end

local function request_country()
  local country = ngx.var.http_x_cdnlite_country or ngx.var.http_cf_ipcountry or ''
  country = string.upper(country)
  if country == '' then
    return 'DEFAULT'
  end
  return country
end

local function pick_upstream(site)
  local geo = site.geo_upstreams or {}
  local country = request_country()
  if geo[country] then
    return geo[country]
  end
  if geo['DEFAULT'] then
    return geo['DEFAULT']
  end
  return site.upstream
end

function M.handle()
  local cfg = loader.load()
  local host = normalize_host(ngx.var.host)
  if not host then
    return false, 'missing_host'
  end

  local site = cfg.hosts[host]
  if not site then
    return false, 'site_not_configured'
  end

  ngx.ctx.site_id = site.site_id
  ngx.ctx.upstream = pick_upstream(site)
  return proxy.forward(site)
end

return M
