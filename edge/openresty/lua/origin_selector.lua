local M = {}

local function selected_origin(domain, country, role)
  if role == 'backup' then
    return domain.backup_origin
  end
  local geo = domain.geo_origins or {}
  return geo[country] or geo.DEFAULT or domain.primary_origin or domain.origin
end

function M.select(domain, country, role)
  local origin = selected_origin(domain, country, role)
  if type(origin) ~= 'table' or type(origin.host) ~= 'string' or origin.host == '' then
    return nil, 'missing_origin'
  end

  if origin.scheme == 'http' and tonumber(origin.port or 80) == 80 then
    return 'http://' .. origin.host .. ':80', 'http'
  end
  if origin.scheme == 'https' and tonumber(origin.port or 443) == 443 then
    return 'https://' .. origin.host .. ':443', 'https'
  end

  local sock = ngx.socket.tcp()
  sock:settimeouts(1000, 1500, 1500)
  local ok = sock:connect(origin.host, 443)
  if ok then
    local verify = tostring(origin.tls_verify or 'verify') ~= 'ignore'
    local session, err = sock:sslhandshake(nil, origin.host, verify)
    sock:close()
    if session then
      return 'https://' .. origin.host .. ':443', 'https'
    end
    ngx.log(ngx.WARN, 'origin_https_handshake_failed host=', origin.host, ' verify=', tostring(verify), ' error=', tostring(err))
  else
    sock:close()
  end

  return 'http://' .. origin.host .. ':80', 'http'
end

return M
