local M = {}

local opened_path = nil
local lookup_cache = {}

local function shell_quote(value)
  return "'" .. tostring(value or ''):gsub("'", "'\\''") .. "'"
end

local function valid_ip(ip)
  ip = tostring(ip or '')
  return ip ~= '' and ip:match('^[0-9A-Fa-f:.]+$') ~= nil
end

local function parse_country(output)
  output = tostring(output or '')
  local country = output:match('"%s*([A-Za-z][A-Za-z])%s*"')
  if country then
    return string.upper(country)
  end
  return nil
end

function M.init(path)
  path = tostring(path or '')
  if path == '' then
    return false, 'missing_path'
  end

  local file, err = io.open(path, 'rb')
  if not file then
    return false, err or 'open_failed'
  end
  file:close()
  opened_path = path
  return true
end

function M.lookup(ip)
  -- Alpine 3.21 no longer ships lua-resty-maxminddb. This compatibility
  -- module uses the libmaxminddb CLI and caches per-IP results so mounted MMDB
  -- files still drive country routing and activity reporting.
  if not opened_path or not valid_ip(ip) then
    return nil
  end
  ip = tostring(ip)
  if lookup_cache[ip] ~= nil then
    return lookup_cache[ip]
  end

  local command = table.concat({
    'mmdblookup',
    '--file', shell_quote(opened_path),
    '--ip', shell_quote(ip),
    'country',
    'iso_code',
  }, ' ')
  local handle = io.popen(command, 'r')
  if not handle then
    lookup_cache[ip] = false
    return nil
  end
  local output = handle:read('*a')
  handle:close()

  local country = parse_country(output)
  if not country then
    lookup_cache[ip] = false
    return nil
  end
  local record = { country = { iso_code = country }, country_code = country }
  lookup_cache[ip] = record
  return record
end

return M
