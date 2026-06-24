local cjson = require('cjson.safe')
local identity = require('identity')
local edge_log = require('edge_log')
local geoip = require('geoip')
local telemetry_queue = require('telemetry_queue')
local M = {}

local function cache_status()
  local value = tostring(ngx.var.upstream_cache_status or '')
  if value == '' then
    return 'UNKNOWN'
  end
  return value
end

local function append_metric(row)
  telemetry_queue.enqueue('metrics', row)
end

function M.on_header()
  identity.apply()
  ngx.header['X-CDNLITE'] = '1'
  ngx.header['X-CDNLITE-Cache'] = cache_status()
end

function M.on_log()
  if ngx.ctx.metrics_logged then
    return
  end
  ngx.ctx.metrics_logged = true

  local bytes_in = tonumber(ngx.var.request_length) or 0
  local bytes_out = tonumber(ngx.var.bytes_sent) or 0
  local row = {
    ts = os.time(),
    domain_id = tostring(ngx.ctx.domain_id or ngx.var.target_domain_id or ''),
    edge_node_id = identity.get(),
    requests_count = 1,
    bytes_in = bytes_in,
    bytes_out = bytes_out,
    status = tonumber(ngx.status) or 0,
    request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''),
    host = tostring(ngx.var.host or ''),
    method = tostring(ngx.req.get_method() or ''),
    path = tostring(ngx.var.uri or ''),
    query = edge_log.redacted_query(),
    client_ip = tostring(ngx.var.remote_addr or ''),
    client_country = tostring(geoip.request_country() or ''),
    cache_status = cache_status(),
    router_error = tostring(ngx.ctx.router_error or ''),
    origin_id = tostring((ngx.ctx.origin or {}).id or ngx.var.target_origin_id or ''),
    origin_role = tostring((ngx.ctx.origin or {}).role or 'origin'),
    origin_host = tostring((ngx.ctx.origin or {}).host or ngx.var.target_origin_host or ''),
    upstream_status = tostring(ngx.var.upstream_status or ''),
    upstream_response_time = tostring(ngx.var.upstream_response_time or ''),
    upstream_addr = tostring(ngx.var.upstream_addr or ''),
    request_time = tonumber(ngx.var.request_time) or 0,
    security_event_type = tostring(ngx.ctx.security_event_type or ''),
    security_action = tostring(ngx.ctx.security_action or ''),
    security_rule_id = tostring(ngx.ctx.security_rule_id or ''),
  }
  append_metric(row)
  if string.lower(tostring(os.getenv('CDNLITE_EDGE_LOG_LEVEL') or 'info')) == 'debug' then
    edge_log.debug('request_metric', row)
  end
end

return M
