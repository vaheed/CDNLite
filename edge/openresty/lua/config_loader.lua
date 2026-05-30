local cjson = require('cjson.safe')

local M = {}
local CONFIG_FILE = '/var/lib/cdnt/config.json'

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
    return { version = 0, hosts = {} }
  end

  local decoded = cjson.decode(raw)
  if not decoded then
    return { version = 0, hosts = {} }
  end

  decoded.hosts = decoded.hosts or {}
  return decoded
end

return M
