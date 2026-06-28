local loader = require('config_loader')
local identity = require('identity')
local telemetry_queue = require('telemetry_queue')

local M = {}

local function origin_key(domain_id, origin)
  return tostring(domain_id or '') .. '|' .. tostring(origin.id or origin.host or '')
end

local function due(last_seen, interval)
  return ngx.now() - (tonumber(last_seen or 0) or 0) >= math.max(5, tonumber(interval or 30) or 30)
end

local function path_for(origin)
  local path = tostring(origin.health_check_path or '/')
  if path == '' or path:sub(1, 1) ~= '/' then
    return '/'
  end
  return path
end

local function probe(domain, origin)
  local started = ngx.now()
  local timeout_ms = math.max(1, tonumber(origin.health_check_timeout_seconds or 5) or 5) * 1000
  local port = tonumber(origin.port or 80) or 80
  local sock = ngx.socket.tcp()
  sock:settimeouts(timeout_ms, timeout_ms, timeout_ms)
  local ok, err = sock:connect(tostring(origin.host or ''), port)
  if not ok then
    sock:close()
    return nil, 'connect_failed:' .. tostring(err or 'unknown'), math.floor((ngx.now() - started) * 1000)
  end

  if tostring(origin.scheme or 'http') == 'https' then
    local sni = tostring(origin.sni or origin.host_header or origin.host or '')
    local verify = tostring(origin.tls_verify or 'ignore') == 'verify'
    local session, tls_err = sock:sslhandshake(nil, sni ~= '' and sni or nil, verify)
    if not session then
      sock:close()
      return nil, 'tls_failed:' .. tostring(tls_err or 'unknown'), math.floor((ngx.now() - started) * 1000)
    end
  end

  local host_header = tostring(origin.host_header or origin.host or '')
  if origin.preserve_host == true then
    host_header = tostring(domain.host or host_header)
  end
  local request = 'GET ' .. path_for(origin) .. ' HTTP/1.1\r\nHost: ' .. host_header .. '\r\nUser-Agent: CDNLite-Edge-Health\r\nConnection: close\r\n\r\n'
  local sent, send_err = sock:send(request)
  if not sent then
    sock:close()
    return nil, 'send_failed:' .. tostring(send_err or 'unknown'), math.floor((ngx.now() - started) * 1000)
  end
  local line, read_err = sock:receive('*l')
  sock:close()
  local latency_ms = math.floor((ngx.now() - started) * 1000)
  if not line then
    return nil, 'read_failed:' .. tostring(read_err or 'unknown'), latency_ms
  end
  local status = tonumber(string.match(line, '^HTTP/%S+%s+(%d+)') or '')
  if not status then
    return nil, 'invalid_http_response', latency_ms
  end
  return status, nil, latency_ms
end

local function collect_origins(cfg)
  local out = {}
  for host, domain in pairs(cfg.hosts or {}) do
    domain.host = host
    for _, origin in ipairs(domain.origins or {}) do
      if origin and origin.enabled ~= false and origin.drain ~= true and origin.health_check_enabled == true then
        out[origin_key(domain.domain_id, origin)] = { domain = domain, origin = origin }
      end
    end
  end
  return out
end

local function tick(premature)
  if premature then
    return
  end
  local cfg = loader.load()
  local seen = ngx.shared.cdnlite_origin_health
  if not seen then
    return
  end
  for key, item in pairs(collect_origins(cfg)) do
    local origin = item.origin
    if due(seen:get(key), origin.health_check_interval_seconds) then
      seen:set(key, ngx.now(), math.max(10, tonumber(origin.health_check_interval_seconds or 30) or 30))
      local status, err, latency_ms = probe(item.domain, origin)
      telemetry_queue.enqueue('metrics', {
        ts = os.time(),
        domain_id = tostring(item.domain.domain_id or ''),
        edge_node_id = identity.get(),
        requests_count = 0,
        bytes_in = 0,
        bytes_out = 0,
        status = status or 0,
        method = 'HEALTH',
        path = path_for(origin),
        host = tostring(item.domain.host or ''),
        cache_status = 'BYPASS',
        origin_id = tostring(origin.id or ''),
        origin_host = tostring(origin.host or ''),
        upstream_status = status and tostring(status) or '',
        upstream_response_time = string.format('%.3f', latency_ms / 1000),
        router_error = err or '',
        origin_health_probe = true,
      })
    end
  end
end

function M.start()
  if ngx.worker.id and ngx.worker.id() ~= 0 then
    return
  end
  local ok, err = ngx.timer.every(5, tick)
  if not ok then
    ngx.log(ngx.ERR, 'origin_health_checker_start_failed: ', tostring(err))
  end
end

return M
