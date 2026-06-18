local loader = require('config_loader')
local ssl = require('ngx.ssl')
local edge_log = require('edge_log')

local M = {}

local function matches_hostname(cert_hostname, sni)
  if cert_hostname == sni then
    return true
  end
  if string.sub(cert_hostname, 1, 2) ~= '*.' then
    return false
  end
  local suffix = string.sub(cert_hostname, 3)
  if string.sub(sni, -#suffix) ~= suffix then
    return false
  end
  if string.sub(sni, -#suffix - 1, -#suffix - 1) ~= '.' then
    return false
  end
  local label = string.sub(sni, 1, #sni - #suffix - 1)
  return label ~= '' and not string.find(label, '%.')
end

function M.apply()
  local sni, err = ssl.server_name()
  if not sni or sni == '' then
    edge_log.debug('tls_default_certificate', { reason = tostring(err or 'missing_sni') })
    return
  end
  sni = string.lower(sni)
  local cfg = loader.load()
  local certs = cfg.ssl_certificates or {}
  for _, c in ipairs(certs) do
    local cert_hostname = c and type(c.hostname) == 'string' and string.lower(c.hostname) or ''
    if matches_hostname(cert_hostname, sni) then
      local cert, cert_err = ssl.parse_pem_cert(c.certificate_pem or '')
      if not cert then
        edge_log.error('tls_certificate_parse_failed', { hostname = sni, error = tostring(cert_err or 'unknown') })
        return
      end
      local pkey, key_err = ssl.parse_pem_priv_key(c.private_key_pem or '')
      if not pkey then
        edge_log.error('tls_key_parse_failed', { hostname = sni, error = tostring(key_err or 'unknown') })
        return
      end
      local ok, clear_err = ssl.clear_certs()
      if not ok then
        edge_log.error('tls_clear_certs_failed', { hostname = sni, error = tostring(clear_err or 'unknown') })
        return
      end
      local okc, setc_err = ssl.set_cert(cert)
      if not okc then
        edge_log.error('tls_set_cert_failed', { hostname = sni, error = tostring(setc_err or 'unknown') })
        return
      end
      local okk, setk_err = ssl.set_priv_key(pkey)
      if not okk then
        edge_log.error('tls_set_key_failed', { hostname = sni, error = tostring(setk_err or 'unknown') })
      end
      edge_log.info('tls_certificate_selected', { hostname = sni, certificate_hostname = cert_hostname })
      return
    end
  end
  edge_log.debug('tls_default_certificate', { hostname = sni, reason = 'certificate_not_configured' })
end

return M
