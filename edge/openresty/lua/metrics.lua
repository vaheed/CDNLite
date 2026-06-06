local cjson = require('cjson.safe')
local identity = require('identity')
local M = {}

local function cache_status()
  local value = tostring(ngx.var.upstream_cache_status or '')
  if value == '' then
    return 'UNKNOWN'
  end
  return value
end

local function append_metric(row)
  local line = cjson.encode(row)
  if not line then return end
  local f = io.open('/var/lib/cdnlite/metrics.ndjson', 'a')
  if not f then return end
  f:write(line .. '\n')
  f:close()
end

function M.on_header()
  identity.apply()
  ngx.header['X-CDNLITE'] = '1'
  ngx.header['X-CDNLITE-Cache'] = cache_status()
end

function M.on_log()
  local bytes_in = tonumber(ngx.var.request_length) or 0
  local bytes_out = tonumber(ngx.var.bytes_sent) or 0
  append_metric({
    ts = os.time(),
    domain_id = tostring(ngx.ctx.domain_id or ''),
    edge_node_id = identity.get(),
    requests_count = 1,
    bytes_in = bytes_in,
    bytes_out = bytes_out,
    status = tonumber(ngx.status) or 0,
    request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''),
    cache_status = cache_status(),
    security_event_type = tostring(ngx.ctx.security_event_type or ''),
    security_action = tostring(ngx.ctx.security_action or ''),
    security_rule_id = tostring(ngx.ctx.security_rule_id or ''),
  })
end

return M
