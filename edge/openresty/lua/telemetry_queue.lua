local cjson = require('cjson.safe')
local edge_log = require('edge_log')

local M = {}

local DEFAULT_METRIC_PATH = '/var/lib/cdnlite/metrics.ndjson'
local DEFAULT_SECURITY_EVENT_PATH = '/var/lib/cdnlite/security-events.ndjson'

local queues = {
  metrics = {
    dict = 'cdnlite_metric_queue',
    path_env = 'METRIC_PATH',
    default_path = DEFAULT_METRIC_PATH,
    prefix = 'metric',
  },
  security_events = {
    dict = 'cdnlite_security_event_queue',
    path_env = 'SECURITY_EVENT_PATH',
    default_path = DEFAULT_SECURITY_EVENT_PATH,
    prefix = 'security_event',
    aggregate = true,
  },
}

local timers_started = false

local function env_number(name, default)
  local value = tonumber(os.getenv(name) or '')
  if not value or value < 0 then
    return default
  end
  return value
end

local function queue_limit()
  return env_number('CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS', 10000)
end

local function byte_limit()
  return env_number('CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES', 1048576)
end

local function batch_size()
  local value = env_number('CDNLITE_EDGE_TELEMETRY_BATCH_SIZE', 100)
  if value < 1 then return 1 end
  return value
end

local function flush_interval()
  local value = env_number('CDNLITE_EDGE_TELEMETRY_FLUSH_INTERVAL_SECONDS', 1)
  if value < 0.1 then return 0.1 end
  return value
end

local function dict_for(spec)
  return ngx.shared[spec.dict]
end

local function get_counter(dict, key)
  return tonumber(dict:get(key) or 0) or 0
end

local function incr(dict, key, value)
  dict:incr(key, value or 1, 0)
end

local function output_path(spec)
  return tostring(os.getenv(spec.path_env) or spec.default_path)
end

local function write_lines(path, lines)
  local f = io.open(path, 'a')
  if not f then
    return false, 'open_failed'
  end
  for _, line in ipairs(lines) do
    f:write(line)
    f:write('\n')
  end
  f:close()
  return true, nil
end

local function aggregate_key(row)
  return table.concat({
    tostring(row.type or ''),
    tostring(row.action or ''),
    tostring(row.rule_id or row.rate_limit_id or ''),
    tostring(row.domain_id or ''),
    tostring(row.path or ''),
    tostring(row.method or ''),
    tostring(row.client_ip or ''),
  }, '|')
end

local function enqueue_encoded(spec, encoded)
  local dict = dict_for(spec)
  if not dict or not encoded then
    return false
  end

  local count = get_counter(dict, 'count')
  local bytes = get_counter(dict, 'bytes')
  local encoded_bytes = #encoded + 1
  if count >= queue_limit() or bytes + encoded_bytes > byte_limit() then
    incr(dict, 'dropped', 1)
    edge_log.warn(spec.prefix .. '_queue_drop', { reason = 'queue_limit', queue_count = tostring(count) })
    return false
  end

  local tail = incr(dict, 'tail', 1)
  local ok, err = dict:set('item:' .. tostring(tail), encoded, 3600)
  if not ok then
    incr(dict, 'dropped', 1)
    edge_log.warn(spec.prefix .. '_queue_drop', { reason = tostring(err or 'set_failed') })
    return false
  end
  incr(dict, 'count', 1)
  incr(dict, 'bytes', encoded_bytes)
  return true
end

function M.enqueue(queue_name, row)
  local spec = queues[queue_name]
  if not spec then
    return false
  end
  if type(row) ~= 'table' then
    return false
  end

  if spec.aggregate then
    row.event_count = tonumber(row.event_count or 1) or 1
    row.aggregate_key = aggregate_key(row)
  end

  local encoded = cjson.encode(row)
  if not encoded then
    local dict = dict_for(spec)
    if dict then incr(dict, 'dropped', 1) end
    return false
  end
  return enqueue_encoded(spec, encoded)
end

function M.flush(queue_name)
  local spec = queues[queue_name]
  if not spec then
    return false
  end
  local dict = dict_for(spec)
  if not dict then
    return false
  end
  local lock_key = 'flush_lock'
  local lock_value = tostring(ngx.worker.pid()) .. ':' .. tostring(ngx.now())
  local locked = dict:add(lock_key, lock_value, 5)
  if not locked then
    return false
  end

  local function unlock()
    if dict:get(lock_key) == lock_value then
      dict:delete(lock_key)
    end
  end

  local lines = {}
  local head = get_counter(dict, 'head')
  local count = get_counter(dict, 'count')
  local limit = math.min(batch_size(), count)
  for _ = 1, limit do
    local next_head = head + 1
    local key = 'item:' .. tostring(next_head)
    local line = dict:get(key)
    if not line then
      incr(dict, 'corruptions', 1)
      dict:delete(key)
      head = next_head
      dict:set('head', head)
      incr(dict, 'count', -1)
    else
      lines[#lines + 1] = line
      head = next_head
    end
  end

  if #lines == 0 then
    unlock()
    return true
  end

  local ok, err = write_lines(output_path(spec), lines)
  if not ok then
    incr(dict, 'flush_failures', 1)
    edge_log.warn(spec.prefix .. '_flush_failed', { error = tostring(err or 'unknown') })
    unlock()
    return false
  end

  for _, line in ipairs(lines) do
    dict:delete('item:' .. tostring(get_counter(dict, 'head') + 1))
    dict:set('head', get_counter(dict, 'head') + 1)
    incr(dict, 'count', -1)
    incr(dict, 'bytes', -(#line + 1))
  end
  incr(dict, 'flush_successes', 1)
  unlock()
  return true
end

local function flush_timer(premature)
  if premature then return end
  M.flush('metrics')
  M.flush('security_events')
  local ok, err = ngx.timer.at(flush_interval(), flush_timer)
  if not ok then
    edge_log.warn('telemetry_flush_timer_failed', { error = tostring(err or 'unknown') })
  end
end

function M.start()
  if timers_started then return end
  if ngx.worker.id() ~= 0 then return end
  timers_started = true
  local ok, err = ngx.timer.at(0, flush_timer)
  if not ok then
    edge_log.warn('telemetry_flush_timer_failed', { error = tostring(err or 'unknown') })
  end
end

function M.status()
  local out = {}
  for name, spec in pairs(queues) do
    local dict = dict_for(spec)
    out[name] = {
      count = dict and get_counter(dict, 'count') or 0,
      bytes = dict and get_counter(dict, 'bytes') or 0,
      dropped = dict and get_counter(dict, 'dropped') or 0,
      flush_successes = dict and get_counter(dict, 'flush_successes') or 0,
      flush_failures = dict and get_counter(dict, 'flush_failures') or 0,
      corruptions = dict and get_counter(dict, 'corruptions') or 0,
      max_items = queue_limit(),
      max_bytes = byte_limit(),
      batch_size = batch_size(),
      flush_interval_seconds = flush_interval(),
    }
  end
  return out
end

return M
