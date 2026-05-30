local loader = require('config_loader')
local proxy = require('proxy')

local M = {}

local function normalize_host(host)
  if not host then return nil end
  return string.lower(host:gsub(':%d+$', ''))
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
  ngx.ctx.upstream = site.upstream
  return proxy.forward(site)
end

return M
