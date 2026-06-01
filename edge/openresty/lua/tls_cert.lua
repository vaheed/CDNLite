local loader = require('config_loader')
local ssl = require('ngx.ssl')

local M = {}

function M.apply()
  local sni, err = ssl.server_name()
  if not sni or sni == '' then
    return
  end
  sni = string.lower(sni)
  local cfg = loader.load()
  local certs = cfg.ssl_certificates or {}
  for _, c in ipairs(certs) do
    if c and type(c.hostname) == 'string' and string.lower(c.hostname) == sni then
      local cert, cert_err = ssl.parse_pem_cert(c.certificate_pem or '')
      if not cert then
        ngx.log(ngx.ERR, 'tls cert parse failed: ', cert_err or 'unknown')
        return
      end
      local pkey, key_err = ssl.parse_pem_priv_key(c.private_key_pem or '')
      if not pkey then
        ngx.log(ngx.ERR, 'tls key parse failed: ', key_err or 'unknown')
        return
      end
      local ok, clear_err = ssl.clear_certs()
      if not ok then
        ngx.log(ngx.ERR, 'tls clear certs failed: ', clear_err or 'unknown')
        return
      end
      local okc, setc_err = ssl.set_cert(cert)
      if not okc then
        ngx.log(ngx.ERR, 'tls set cert failed: ', setc_err or 'unknown')
        return
      end
      local okk, setk_err = ssl.set_priv_key(pkey)
      if not okk then
        ngx.log(ngx.ERR, 'tls set key failed: ', setk_err or 'unknown')
      end
      return
    end
  end
end

return M
