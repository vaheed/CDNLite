local M = {}

function M.forward(site)
  local upstream = ngx.ctx.upstream or site.upstream
  if not upstream then
    return false, 'missing_upstream'
  end

  ngx.var.target_upstream = upstream
  ngx.header['X-CDNLITE-Edge'] = 'openresty'
  ngx.header['X-CDNLITE-Site'] = tostring(site.site_id)
  return true
end

return M
