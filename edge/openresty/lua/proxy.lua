local M = {}

function M.forward(site)
  local upstream = site.upstream
  if not upstream then
    return false, 'missing_upstream'
  end

  ngx.var.target_upstream = upstream
  ngx.header['X-CDNT-Edge'] = 'openresty'
  ngx.header['X-CDNT-Site'] = tostring(site.site_id)
  return true
end

return M
