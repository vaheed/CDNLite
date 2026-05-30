local loader = require('config_loader')

local M = {}

function M.start()
  ngx.shared.cdnt_hosts = ngx.shared.cdnt_hosts or ngx.shared.DICT
  local cfg = loader.load()
  ngx.shared.cdnt_state = ngx.shared.cdnt_state or ngx.shared.DICT
  ngx.shared.cdnt_state:set('config_version', cfg.version or 0)
end

return M
