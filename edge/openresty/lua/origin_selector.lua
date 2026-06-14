local M = {}

local function selected_origin(domain, country, role)
  if role == 'backup' then
    return domain.backup_origin
  end
  local geo = domain.geo_origins or {}
  return geo[country] or geo.DEFAULT or domain.primary_origin or domain.origin
end

local function first_enabled_by_role(domain, role)
  local origins = domain.origins or {}
  for _, origin in ipairs(origins) do
    if origin and origin.enabled ~= false and tostring(origin.role or 'primary') == role then
      return origin
    end
  end
  return nil
end

local function origin_for(domain, country, role)
  local selected = selected_origin(domain, country, role)
  if selected then
    return selected
  end
  return first_enabled_by_role(domain, role)
end

local function origin_port(origin, scheme)
  local port = tonumber(origin.port or '')
  if port and port > 0 then
    return math.floor(port)
  end
  if scheme == 'https' then
    return 443
  end
  return 80
end

local function origin_sni(origin, host_header)
  local sni = tostring(origin.sni or '')
  if sni ~= '' then
    return sni
  end
  if host_header and host_header ~= '' then
    return host_header
  end
  return tostring(origin.host or '')
end

function M.select(domain, country, role)
  local origin = origin_for(domain, country, role)
  if type(origin) ~= 'table' or type(origin.host) ~= 'string' or origin.host == '' then
    return nil, 'missing_origin'
  end

  local scheme = tostring(origin.scheme or 'http')
  if scheme == 'http' or scheme == 'https' then
    local port = origin_port(origin, scheme)
    local host_header = tostring(origin.host_header or '')
    if host_header == '' then
      host_header = origin.host
    end
    if origin.preserve_host == true then
      host_header = tostring(ngx.var.host or host_header)
    end
    return scheme .. '://' .. origin.host .. ':' .. tostring(port), {
      scheme = scheme,
      port = port,
      id = tostring(origin.id or ''),
      role = tostring(origin.role or role or ''),
      source = tostring(origin.source or ''),
      host = tostring(origin.host or ''),
      host_header = host_header,
      sni = origin_sni(origin, host_header),
      tls_verify = tostring(origin.tls_verify or 'verify'),
      preserve_host = origin.preserve_host == true,
    }
  end

  if scheme ~= 'auto' then
    return nil, 'invalid_origin_scheme'
  end

  local sock = ngx.socket.tcp()
  sock:settimeouts(1000, 1500, 1500)
  local ok = sock:connect(origin.host, 443)
  if ok then
    local verify = tostring(origin.tls_verify or 'verify') ~= 'ignore'
    local session, err = sock:sslhandshake(nil, origin.host, verify)
    sock:close()
    if session then
      return 'https://' .. origin.host .. ':443', {
        scheme = 'https',
        port = 443,
        id = tostring(origin.id or ''),
        role = tostring(origin.role or role or ''),
        source = tostring(origin.source or ''),
        host = tostring(origin.host or ''),
        host_header = tostring(origin.host_header or origin.host),
        sni = origin_sni(origin, tostring(origin.host_header or origin.host)),
        tls_verify = tostring(origin.tls_verify or 'verify'),
        preserve_host = origin.preserve_host == true,
      }
    end
    ngx.log(ngx.WARN, 'origin_https_handshake_failed host=', origin.host, ' verify=', tostring(verify), ' error=', tostring(err))
  else
    sock:close()
  end

  return 'http://' .. origin.host .. ':80', {
    scheme = 'http',
    port = 80,
    id = tostring(origin.id or ''),
    role = tostring(origin.role or role or ''),
    source = tostring(origin.source or ''),
    host = tostring(origin.host or ''),
    host_header = tostring(origin.host_header or origin.host),
    sni = origin_sni(origin, tostring(origin.host_header or origin.host)),
    tls_verify = tostring(origin.tls_verify or 'verify'),
    preserve_host = origin.preserve_host == true,
  }
end

return M
