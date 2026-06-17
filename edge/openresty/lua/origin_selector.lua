local M = {}

local function healthy_rank(status)
  if status == 'healthy' then
    return 0
  end
  if status == 'unknown' or status == nil or status == '' then
    return 1
  end
  return 2
end

local function sort_origins(origins)
  table.sort(origins, function(a, b)
    local rank_a = healthy_rank(a.health_status)
    local rank_b = healthy_rank(b.health_status)
    if rank_a ~= rank_b then
      return rank_a < rank_b
    end

    local weight_a = tonumber(a.weight or 1) or 1
    local weight_b = tonumber(b.weight or 1) or 1
    if weight_a ~= weight_b then
      return weight_a < weight_b
    end

    return tostring(a.id or '') < tostring(b.id or '')
  end)
end

local function eligible_origins(domain)
  local origins = {}
  for _, origin in ipairs(domain.origins or {}) do
    if origin and origin.enabled ~= false and type(origin.host) == 'string' and origin.host ~= '' then
      table.insert(origins, origin)
    end
  end
  sort_origins(origins)
  return origins
end

local function candidate_origins(domain, country)
  local geo = domain.geo_origins or {}
  local geo_origin = geo[country] or geo.DEFAULT
  if type(geo_origin) == 'table' and geo_origin.enabled ~= false and type(geo_origin.host) == 'string' and geo_origin.host ~= '' and tostring(geo_origin.health_status or 'unknown') ~= 'unhealthy' then
    return { geo_origin }, 'geo_origins.' .. tostring(country or 'DEFAULT')
  end
  return eligible_origins(domain), 'origins'
end

local function choose_origin(origins, seed)
  if #origins == 0 then
    return nil
  end

  local healthy = {}
  local unknown = {}
  for _, origin in ipairs(origins) do
    local status = tostring(origin.health_status or '')
    if status == 'healthy' then
      table.insert(healthy, origin)
    elseif status ~= 'unhealthy' then
      table.insert(unknown, origin)
    end
  end

  local pool = healthy
  if #pool == 0 then
    pool = unknown
  end
  if #pool == 0 then
    return nil
  end
  if #pool == 1 then
    return pool[1]
  end

  local hash = ngx.crc32_short(seed or '') or 0
  local index = (tonumber(hash) or 0) % #pool + 1
  return pool[index]
end

local function origin_port(origin)
  local port = tonumber(origin.port or '')
  if port and port > 0 then
    return math.floor(port)
  end
  return tostring(origin.scheme or '') == 'https' and 443 or 80
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

local function build_metadata(origin, role)
  local host_header = tostring(origin.host_header or '')
  if host_header == '' then
    host_header = origin.host
  end
  if origin.preserve_host == true then
    host_header = tostring(ngx.var.host or host_header)
  end
  return {
    scheme = tostring(origin.scheme or 'http'),
    port = origin_port(origin),
    id = tostring(origin.id or ''),
    role = role or 'origin',
    source = tostring(origin.source or ''),
    host = tostring(origin.host or ''),
    host_header = host_header,
    sni = origin_sni(origin, host_header),
    tls_verify = tostring(origin.tls_verify or 'ignore'),
    preserve_host = origin.preserve_host == true,
    health_status = tostring(origin.health_status or 'unknown'),
    weight = tonumber(origin.weight or 1) or 1,
  }
end

function M.select(domain, country, seed)
  local origins, origin_source = candidate_origins(domain, country)
  local origin = choose_origin(origins, tostring(seed or ngx.var.request_id or ngx.ctx.request_id or ngx.var.uri or ''))
  if type(origin) ~= 'table' or type(origin.host) ~= 'string' or origin.host == '' then
    return nil, 'no_healthy_origin'
  end

  local scheme = tostring(origin.scheme or 'http')
  if scheme == 'http' or scheme == 'https' then
    local port = origin_port(origin)
    return scheme .. '://' .. origin.host .. ':' .. tostring(port), build_metadata(origin, origin_source)
  end

  if scheme ~= 'auto' then
    return nil, 'invalid_origin_scheme'
  end

  local sock = ngx.socket.tcp()
  sock:settimeouts(1000, 1500, 1500)
  local ok = sock:connect(origin.host, 443)
  if ok then
    local verify = tostring(origin.tls_verify or 'ignore') ~= 'ignore'
    local session, err = sock:sslhandshake(nil, origin.host, verify)
    sock:close()
    if session then
      return 'https://' .. origin.host .. ':443', build_metadata(origin, origin_source)
    end
    ngx.log(ngx.WARN, 'origin_https_handshake_failed host=', origin.host, ' verify=', tostring(verify), ' error=', tostring(err))
  else
    sock:close()
  end

  return 'http://' .. origin.host .. ':80', build_metadata(origin, origin_source)
end

return M
