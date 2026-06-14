local cjson = require('cjson.safe')
local edge_log = require('edge_log')

local M = {}
local CONFIG_FILE = '/var/lib/cdnlite/config.json'
local STATUS_FILE = '/var/lib/cdnlite/edge-sync-status.json'
local EXPECTED_SCHEMA_VERSION = 1

local function read_file(path)
  local f = io.open(path, 'r')
  if not f then return nil end
  local content = f:read('*a')
  f:close()
  return content
end

function M.load()
  local raw = read_file(CONFIG_FILE)
  if not raw then
    edge_log.warn('config_missing', { config_file = CONFIG_FILE })
    return { schema_version = EXPECTED_SCHEMA_VERSION, version = 0, hosts = {} }
  end

  local decoded = cjson.decode(raw)
  if not decoded then
    edge_log.error('config_invalid_json', { config_file = CONFIG_FILE })
    return { schema_version = EXPECTED_SCHEMA_VERSION, version = 0, hosts = {} }
  end

  if decoded.schema_version == nil then
    decoded.schema_version = EXPECTED_SCHEMA_VERSION
  end

  if decoded.schema_version ~= EXPECTED_SCHEMA_VERSION then
    edge_log.error('config_schema_unsupported', { schema_version = tostring(decoded.schema_version or '') })
    return { schema_version = EXPECTED_SCHEMA_VERSION, version = 0, hosts = {} }
  end

  decoded.hosts = decoded.hosts or {}
  edge_log.debug('config_loaded', { version = tostring(decoded.version or 0), hosts_count = tostring(#decoded.hosts) })
  return decoded
end

local function now_epoch()
  return os.time(os.date("!*t"))
end

function M.ready()
  local raw = read_file(CONFIG_FILE)
  if not raw then
    return false, 'config_missing'
  end

  local decoded = cjson.decode(raw)
  if not decoded then
    return false, 'config_invalid_json'
  end

  if type(decoded.hosts) ~= 'table' then
    return false, 'config_hosts_invalid'
  end
  local schema_version = decoded.schema_version
  if schema_version == nil then
    schema_version = EXPECTED_SCHEMA_VERSION
  end
  if tonumber(schema_version) ~= EXPECTED_SCHEMA_VERSION then
    return false, 'config_schema_unsupported'
  end

  local status_raw = read_file(STATUS_FILE)
  local status = status_raw and cjson.decode(status_raw) or {}
  local max_stale = tonumber(os.getenv('EDGE_CONFIG_MAX_STALE_SECONDS') or '') or 0
  local sync_ts = tonumber(status and status.last_successful_sync_time or '') or nil
  local stale_age = nil
  local warning = nil
  if sync_ts then
    stale_age = now_epoch() - sync_ts
    if max_stale > 0 and stale_age > max_stale then
      warning = 'config_stale'
    end
  end

  return true, nil, {
    current_config_version = decoded.version,
    last_successful_sync_time = sync_ts,
    config_source = status and status.config_source or 'unknown',
    core_reachable = status and status.core_reachable or false,
    stale_age_seconds = stale_age,
    warning = warning,
  }
end

return M
