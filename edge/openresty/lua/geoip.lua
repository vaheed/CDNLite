local M = {}

local edge_log = require('edge_log')

local DEFAULT_MMDB_PATH = '/var/lib/cdnlite/mmdb/GeoLite2-City.mmdb'
local mmdb_path = os.getenv('CDNLITE_EDGE_MMDB_FILE') or DEFAULT_MMDB_PATH
local initialized = false
local available = false
local maxminddb = nil

local function normalize_country(value)
  value = tostring(value or ''):upper()
  if value:match('^[A-Z][A-Z]$') then
    return value
  end
  return ''
end

local function init()
  if initialized then
    return available
  end
  initialized = true
  local ok, lib = pcall(require, 'resty.maxminddb')
  if not ok or not lib then
    edge_log.warn('geoip_module_unavailable', { error = tostring(lib or 'missing') })
    return false
  end
  maxminddb = lib
  local ok_init, opened, err = pcall(function()
    return maxminddb.init(mmdb_path)
  end)
  if not ok_init or not opened then
    edge_log.warn('geoip_database_unavailable', { path = mmdb_path, error = tostring(err or opened or 'init_failed') })
    return false
  end
  available = true
  return true
end

local function country_from_record(record)
  if type(record) ~= 'table' then
    return ''
  end
  local country = record.country
  if type(country) == 'table' then
    return normalize_country(country.iso_code or country.isoCode)
  end
  return normalize_country(record.country_code or record.countryCode)
end

function M.country_for_ip(ip)
  ip = tostring(ip or '')
  if ip == '' or not init() then
    return ''
  end
  local ok, record = pcall(function()
    return maxminddb.lookup(ip)
  end)
  if not ok then
    edge_log.warn('geoip_lookup_failed', { ip = ip, error = tostring(record or 'lookup_failed') })
    return ''
  end
  return country_from_record(record)
end

function M.request_country()
  -- Explicit headers remain useful for local tests and trusted upstream CDN
  -- integrations. Standalone edges fall back to the mounted MMDB.
  local header_country = normalize_country(ngx.var.http_x_cdnlite_country or ngx.var.http_cf_ipcountry)
  if header_country ~= '' then
    return header_country
  end
  local mmdb_country = M.country_for_ip(ngx.var.remote_addr)
  if mmdb_country ~= '' then
    return mmdb_country
  end
  return 'DEFAULT'
end

return M
