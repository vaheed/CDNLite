local cjson = require('cjson.safe')
local identity = require('identity')

local M = {}

local levels = { debug = 0, info = 1, warn = 2, error = 3 }
local ngx_levels = {
  debug = ngx.DEBUG,
  info = ngx.INFO,
  warn = ngx.WARN,
  error = ngx.ERR,
}

local sensitive_keys = {
  authorization = true,
  cookie = true,
  token = true,
  key = true,
  secret = true,
  password = true,
  auth = true,
  signature = true,
}

local function configured_level()
  local value = string.lower(tostring(os.getenv('CDNLITE_EDGE_LOG_LEVEL') or 'info'))
  if levels[value] == nil then
    return 'info'
  end
  return value
end

local function should_log(level)
  return levels[level] >= levels[configured_level()]
end

local function redact_value(key, value)
  local k = string.lower(tostring(key or ''))
  if sensitive_keys[k] or string.find(k, 'token', 1, true) or string.find(k, 'secret', 1, true) then
    return '[redacted]'
  end
  return value
end

local function redacted_query()
  local args = ngx.req.get_uri_args(64)
  local out = {}
  for key, value in pairs(args) do
    local k = tostring(key)
    local lk = string.lower(k)
    if sensitive_keys[lk] or string.find(lk, 'token', 1, true) or string.find(lk, 'secret', 1, true) then
      out[k] = '[redacted]'
    elseif type(value) == 'table' then
      out[k] = table.concat(value, ',')
    else
      out[k] = tostring(value)
    end
  end
  return out
end

function M.redacted_query()
  return redacted_query()
end

function M.event(level, event, fields)
  level = level or 'info'
  if not should_log(level) then
    return
  end

  local row = {
    ts = ngx.var.time_iso8601 or tostring(os.time()),
    level = level,
    event = tostring(event or 'edge_event'),
    request_id = tostring(ngx.ctx.request_id or ngx.var.request_id or ''),
    edge_node_id = identity.get(),
    host = tostring(ngx.var.host or ''),
    method = tostring(ngx.req.get_method() or ''),
    path = tostring(ngx.var.uri or ''),
  }
  for key, value in pairs(fields or {}) do
    row[key] = redact_value(key, value)
  end

  local encoded = cjson.encode(row)
  if encoded then
    ngx.log(ngx_levels[level] or ngx.INFO, encoded)
  end
end

function M.debug(event, fields) M.event('debug', event, fields) end
function M.info(event, fields) M.event('info', event, fields) end
function M.warn(event, fields) M.event('warn', event, fields) end
function M.error(event, fields) M.event('error', event, fields) end

return M
