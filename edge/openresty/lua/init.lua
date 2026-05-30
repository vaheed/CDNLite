local loader = require('config_loader')

local M = {}

function M.start()
  ngx.shared.cdnlite_hosts = ngx.shared.cdnlite_hosts or ngx.shared.DICT
  local cfg = loader.load()
  ngx.shared.cdnlite_state = ngx.shared.cdnlite_state or ngx.shared.DICT
  ngx.shared.cdnlite_state:set('config_version', cfg.version or 0)
end

return M
