local M = {}

local opened_path = nil

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

function M.lookup(_ip)
  -- Alpine 3.21 no longer ships lua-resty-maxminddb. This compatibility
  -- module keeps edge builds deterministic and lets header-based country
  -- routing continue while deployments install a native module if needed.
  if not opened_path then
    return nil
  end
  return nil
end

return M
