local cjson = require('cjson.safe')
local M = {}

local function append_metric(row)
  local line = cjson.encode(row)
  if not line then return end
  local f = io.open('/var/lib/cdnlite/metrics.ndjson', 'a')
  if not f then return end
  f:write(line .. '\n')
  f:close()
end

function M.on_header()
  ngx.header['X-CDNLITE'] = '1'
end

function M.on_log()
  local bytes_in = tonumber(ngx.var.request_length) or 0
  local bytes_out = tonumber(ngx.var.bytes_sent) or 0
  append_metric({
    ts = os.time(),
    site_id = tostring(ngx.ctx.site_id or ''),
    edge_node_id = os.getenv('EDGE_ID') or 'edge-local-1',
    requests_count = 1,
    bytes_in = bytes_in,
    bytes_out = bytes_out,
    status = tonumber(ngx.status) or 0,
  })
end

return M
