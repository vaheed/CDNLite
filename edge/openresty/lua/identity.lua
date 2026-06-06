local M = {}

local edge_id = tostring(os.getenv('EDGE_ID') or '')
if edge_id == '' then
  edge_id = 'unknown'
end

function M.get()
  return edge_id
end

function M.apply()
  ngx.ctx.edge_id = edge_id
  ngx.header['X-CDNLITE-Edge'] = edge_id
  return edge_id
end

return M
