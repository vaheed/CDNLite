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

local function health_check_enabled(origin)
  return origin.health_check_enabled == true
end

local function role_rank(origin)
  local role = tostring(origin.role or 'primary')
  if role == 'primary' then
    return 0
  end
  if role == 'shield' then
    return 1
  end
  if role == 'backup' then
    return 2
  end
  return 3
end

local function is_ip_address(host)
  host = tostring(host or '')
  if host:match('^%d+%.%d+%.%d+%.%d+$') then
    return true
  end
  return host:find(':', 1, true) ~= nil
end

local function sort_origins(origins)
  table.sort(origins, function(a, b)
    local rank_a = healthy_rank(a.health_status)
    local rank_b = healthy_rank(b.health_status)
    if rank_a ~= rank_b then
      return rank_a < rank_b
    end

    local role_a = role_rank(a)
    local role_b = role_rank(b)
    if role_a ~= role_b then
      return role_a < role_b
    end

    local weight_a = tonumber(a.weight or 1) or 1
    local weight_b = tonumber(b.weight or 1) or 1
    if weight_a ~= weight_b then
      return weight_a > weight_b
    end

    return tostring(a.id or '') < tostring(b.id or '')
  end)
end

local function eligible_origins(domain)
  local origins = {}
  for _, origin in ipairs(domain.origins or {}) do
    if origin and origin.enabled ~= false and origin.drain ~= true and type(origin.host) == 'string' and origin.host ~= '' then
      if not (health_check_enabled(origin) and tostring(origin.health_status or 'unknown') == 'unhealthy') then
        table.insert(origins, origin)
      end
    end
  end
  sort_origins(origins)
  return origins
end

local function candidate_origins(domain, country)
  local geo = domain.geo_origins or {}
  local geo_origin = geo[country] or geo.DEFAULT
  if type(geo_origin) == 'table' and geo_origin.enabled ~= false and geo_origin.drain ~= true and type(geo_origin.host) == 'string' and geo_origin.host ~= '' and not (health_check_enabled(geo_origin) and tostring(geo_origin.health_status or 'unknown') == 'unhealthy') then
    return { geo_origin }, 'geo_origins.' .. tostring(country or 'DEFAULT')
  end
  return eligible_origins(domain), 'origins'
end

local function weighted_pick(pool, seed)
  local total = 0
  for _, origin in ipairs(pool) do
    local weight = tonumber(origin.weight or 1) or 1
    if weight > 0 then
      total = total + math.floor(weight)
    end
  end
  if total <= 0 then
    return pool[1]
  end

  local hash = ngx.crc32_short(seed or '') or 0
  local slot = (tonumber(hash) or 0) % total
  local seen = 0
  for _, origin in ipairs(pool) do
    local weight = tonumber(origin.weight or 1) or 1
    if weight > 0 then
      seen = seen + math.floor(weight)
      if slot < seen then
        return origin
      end
    end
  end
  return pool[1]
end

local function choose_origin(origins, seed)
  if #origins == 0 then
    return nil
  end

  local healthy_primary = {}
  local unknown_primary = {}
  local healthy_backup = {}
  local unknown_backup = {}
  for _, origin in ipairs(origins) do
    local status = tostring(origin.health_status or '')
    local backup = tostring(origin.role or 'primary') == 'backup'
    if status == 'healthy' then
      table.insert(backup and healthy_backup or healthy_primary, origin)
    elseif not health_check_enabled(origin) or status ~= 'unhealthy' then
      table.insert(backup and unknown_backup or unknown_primary, origin)
    end
  end

  local pool = healthy_primary
  if #pool == 0 then pool = unknown_primary end
  if #pool == 0 then pool = healthy_backup end
  if #pool == 0 then pool = unknown_backup end
  if #pool == 0 then
    return nil
  end
  if #pool == 1 then
    return pool[1]
  end

  return weighted_pick(pool, seed)
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
  if origin.preserve_host == true and host_header and host_header ~= '' then
    return host_header
  end
  local host = tostring(origin.host or '')
  if host ~= '' and not is_ip_address(host) then
    return host
  end
  return ''
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
    role = role or 'primary',
    source = tostring(origin.source or ''),
    host = tostring(origin.host or ''),
    host_header = host_header,
    sni = origin_sni(origin, host_header),
    tls_verify = tostring(origin.tls_verify or 'ignore'),
    preserve_host = origin.preserve_host == true,
    health_check_enabled = origin.health_check_enabled == true,
    health_status = tostring(origin.health_status or 'unknown'),
    weight = tonumber(origin.weight or 1) or 1,
    load_balancing_algorithm = tostring(origin.load_balancing_algorithm or 'weighted_hash'),
    retry_attempts = tonumber(origin.retry_attempts or 1) or 1,
    retry_budget_per_minute = tonumber(origin.retry_budget_per_minute or 60) or 60,
    circuit_breaker_enabled = origin.circuit_breaker_enabled ~= false,
    circuit_failure_threshold = tonumber(origin.circuit_failure_threshold or 5) or 5,
    circuit_recovery_seconds = tonumber(origin.circuit_recovery_seconds or 30) or 30,
    max_concurrent_requests = tonumber(origin.max_concurrent_requests or 0) or 0,
    drain = origin.drain == true,
    shield_enabled = origin.shield_enabled == true,
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

  local meta = build_metadata(origin, origin_source)
  local sock = ngx.socket.tcp()
  sock:settimeouts(1000, 1500, 1500)
  local ok = sock:connect(origin.host, 443)
  if ok then
    local verify = tostring(origin.tls_verify or 'ignore') ~= 'ignore'
    local sni = meta.sni ~= '' and meta.sni or nil
    local session, err = sock:sslhandshake(nil, sni, verify)
    sock:close()
    if session then
      return 'https://' .. origin.host .. ':443', meta
    end
    ngx.log(ngx.WARN, 'origin_https_handshake_failed host=', origin.host, ' verify=', tostring(verify), ' error=', tostring(err))
  else
    sock:close()
  end

  return 'http://' .. origin.host .. ':80', meta
end

return M
