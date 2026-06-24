local cjson = require('cjson.safe')
local edge_log = require('edge_log')

local M = {}
local CONFIG_FILE = '/var/lib/cdnlite/config.json'
local STATUS_FILE = '/var/lib/cdnlite/edge-sync-status.json'
local EXPECTED_SCHEMA_VERSION = 1
local EMPTY_CONFIG = { schema_version = EXPECTED_SCHEMA_VERSION, version = 0, hosts = {} }

local active_config = nil
local active_mtime = nil
local active_size = nil
local active_loaded_at = nil
local active_checksum = nil
local reload_successes = 0
local reload_failures = 0
local last_reload_error = nil

local function read_file(path)
  local f = io.open(path, 'r')
  if not f then return nil end
  local content = f:read('*a')
  f:close()
  return content
end

local function max_config_bytes()
  return tonumber(os.getenv('CDNLITE_EDGE_CONFIG_MAX_BYTES') or '') or 1048576
end

local function refresh_interval()
  return tonumber(os.getenv('CDNLITE_EDGE_CONFIG_REFRESH_SECONDS') or '') or 1
end

local function checksum(raw)
  return tostring(ngx.crc32_long(raw or ''))
end

local function file_stat(path)
  local ok, info = pcall(function()
    return ngx.stat(path)
  end)
  if ok and info then
    return tonumber(info.mtime), tonumber(info.size)
  end
  return nil, nil
end

local function validate(decoded)
  if type(decoded) ~= 'table' then
    return nil, 'config_not_object'
  end

  if decoded.schema_version == nil then
    decoded.schema_version = EXPECTED_SCHEMA_VERSION
  end

  if tonumber(decoded.schema_version) ~= EXPECTED_SCHEMA_VERSION then
    return nil, 'config_schema_unsupported'
  end

  if decoded.hosts == nil then
    decoded.hosts = {}
  end
  if type(decoded.hosts) ~= 'table' then
    return nil, 'config_hosts_invalid'
  end

  decoded.version = tonumber(decoded.version or 0) or 0
  return decoded, nil
end

local function load_from_disk()
  local mtime, size = file_stat(CONFIG_FILE)
  if size and size > max_config_bytes() then
    return nil, 'config_too_large', mtime, size
  end

  local raw = read_file(CONFIG_FILE)
  if not raw then
    return nil, 'config_missing', mtime, size
  end

  local decoded = cjson.decode(raw)
  if not decoded then
    return nil, 'config_invalid_json', mtime, size
  end

  local valid, err = validate(decoded)
  if not valid then
    return nil, err, mtime, size
  end

  return valid, nil, mtime, size, checksum(raw)
end

local function install_config(decoded, mtime, size, sum)
  active_config = decoded
  active_mtime = mtime
  active_size = size
  active_loaded_at = ngx.now()
  active_checksum = sum
  reload_successes = reload_successes + 1
  last_reload_error = nil
end

function M.reload()
  local decoded, err, mtime, size, sum = load_from_disk()
  if not decoded then
    reload_failures = reload_failures + 1
    last_reload_error = err
    if active_config then
      edge_log.warn('config_reload_rejected', { error = tostring(err or 'unknown') })
      return active_config
    end
    edge_log.warn('config_reload_empty', { error = tostring(err or 'unknown') })
    install_config(EMPTY_CONFIG, mtime, size, checksum('{}'))
    return active_config
  end

  install_config(decoded, mtime, size, sum)
  edge_log.debug('config_loaded', {
    version = tostring(decoded.version or 0),
    checksum = tostring(sum or ''),
    hosts_count = tostring(#decoded.hosts),
  })
  return active_config
end

function M.load()
  local now = ngx.now()
  local mtime, size = file_stat(CONFIG_FILE)
  if active_config and active_loaded_at and now - active_loaded_at < refresh_interval() then
    return active_config
  end
  if active_config and (mtime ~= nil or size ~= nil) and active_mtime == mtime and active_size == size then
    active_loaded_at = now
    return active_config
  end
  return M.reload()
end

local function now_epoch()
  return os.time(os.date("!*t"))
end

function M.ready()
  local decoded = M.load()
  if not decoded then
    return false, 'config_missing'
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
    current_config_checksum = active_checksum,
    active_load_time = active_loaded_at,
    reload_successes = reload_successes,
    reload_failures = reload_failures,
    last_reload_error = last_reload_error,
    last_successful_sync_time = sync_ts,
    config_source = status and status.config_source or 'unknown',
    core_reachable = status and status.core_reachable or false,
    stale_age_seconds = stale_age,
    warning = warning,
  }
end

return M
