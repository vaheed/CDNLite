local M = {}

local function path_matches(pattern, path)
  pattern = tostring(pattern or '/*')
  path = tostring(path or '/')
  if pattern == '' or pattern == '/*' or pattern == '*' then
    return true
  end
  if string.sub(pattern, -1) == '*' then
    local prefix = string.sub(pattern, 1, -2)
    return string.sub(path, 1, #prefix) == prefix
  end
  return path == pattern
end

local function append_header(name, value)
  local existing = ngx.header[name]
  if existing == nil or existing == '' then
    ngx.header[name] = value
    return
  end
  if type(existing) == 'table' then
    table.insert(existing, value)
    ngx.header[name] = existing
    return
  end
  ngx.header[name] = tostring(existing) .. ', ' .. value
end

function M.apply()
  local rules = ngx.ctx.header_rules or {}
  if type(rules) ~= 'table' then
    return
  end
  table.sort(rules, function(a, b)
    local ap = tonumber(a and a.priority or 100) or 100
    local bp = tonumber(b and b.priority or 100) or 100
    return ap < bp
  end)
  local path = ngx.var.uri or '/'
  for _, rule in ipairs(rules) do
    if rule and rule.enabled and path_matches(rule.path_pattern, path) then
      local name = tostring(rule.header_name or '')
      local operation = tostring(rule.operation or 'set')
      local value = tostring(rule.header_value or '')
      if name ~= '' then
        if operation == 'remove' then
          ngx.header[name] = nil
        elseif operation == 'append' then
          append_header(name, value)
        else
          ngx.header[name] = value
        end
      end
    end
  end
end

return M
