local M = {}
local identity = require('identity')

local function h(v)
  local s = tostring(v or "")
  s = s:gsub("&", "&amp;")
  s = s:gsub("<", "&lt;")
  s = s:gsub(">", "&gt;")
  s = s:gsub('"', "&quot;")
  return s
end

local function details(code)
  local map = {
    [500] = {
      label = "500 CDN Error",
      headline = "We could not load this page",
      summary = "The request reached the CDNLite edge, but an internal edge error interrupted the response.",
      edge_status = "Degraded",
      origin_status = "Not checked",
      owner_tips = {
        "Check recent edge diagnostics by request ID.",
        "Verify the active config snapshot and edge readiness.",
        "Review custom rules that may run before proxying.",
      },
    },
    [502] = {
      label = "502 Origin Error",
      headline = "We could not load this page",
      summary = "Your browser reached the CDNLite edge, but the origin server was unreachable or returned an invalid response.",
      edge_status = "Working",
      origin_status = "Unreachable",
      owner_tips = {
        "Confirm the origin is online and accepting traffic from the edge.",
        "Check the configured origin host header, port, and TLS/SNI settings.",
        "Search Activity or edge logs for this request ID.",
      },
    },
    [503] = {
      label = "503 Service Unavailable",
      headline = "This site is temporarily unavailable",
      summary = "The CDNLite edge is reachable, but the origin service is currently unavailable.",
      edge_status = "Working",
      origin_status = "Unavailable",
      owner_tips = {
        "Check origin capacity, maintenance mode, and health checks.",
        "Verify the enabled origins are healthy.",
        "Review recent deploys or firewall changes.",
      },
    },
    [504] = {
      label = "504 Origin Timeout",
      headline = "The origin took too long to respond",
      summary = "The CDNLite edge connected to the site, but the origin did not respond before the timeout.",
      edge_status = "Working",
      origin_status = "Timeout",
      owner_tips = {
        "Check origin latency, upstream dependencies, and timeout settings.",
        "Look for slow requests using the request ID.",
        "Consider adding another healthy origin.",
      },
    },
  }
  return map[code] or {
    label = tostring(code) .. " Error",
    headline = "We could not load this page",
    summary = "The request reached CDNLite, but the page could not be loaded.",
    edge_status = "Working",
    origin_status = "Error",
    owner_tips = { "Search edge diagnostics using the request ID." },
  }
end

local function icon(name)
  local icons = {
    browser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.8A1.8 1.8 0 0 1 5.8 4h12.4A1.8 1.8 0 0 1 20 5.8v12.4a1.8 1.8 0 0 1-1.8 1.8H5.8A1.8 1.8 0 0 1 4 18.2V5.8Zm0 3.2h16M7 6.5h.01M10 6.5h.01"/><path d="m8 14 2.2 2.2L16 10.5"/></svg>',
    edge = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.8 7.2v8.6L12 20l7.2-4.2V7.2L12 3Z"/><path d="M12 8v8M8.5 10l7 4M15.5 10l-7 4"/></svg>',
    origin = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2M8 13h.01M12 13h.01M16 13h.01"/></svg>',
    alert = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4M12 17h.01"/></svg>',
  }
  return icons[name] or icons.alert
end

local function list_items(items)
  local out = {}
  for _, item in ipairs(items or {}) do
    out[#out + 1] = "<li>" .. h(item) .. "</li>"
  end
  return table.concat(out, "")
end

local function action_link(url, label, primary)
  if not url or url == "" then
    return ""
  end
  local class = primary and "button primary" or "button"
  return '<a class="' .. class .. '" href="' .. h(url) .. '">' .. h(label) .. '</a>'
end

local function detail_row(label, value)
  if value == nil or value == "" then
    return ""
  end
  return '<div class="detail-row"><dt>' .. h(label) .. '</dt><dd>' .. h(value) .. '</dd></div>'
end

local function safe_context_value(name)
  local value = ngx.ctx[name]
  if value == nil or value == "" then
    return nil
  end
  return tostring(value)
end

function M.render(code)
  local info = details(code)
  local reqid = ngx.ctx.request_id or ngx.var.request_id
  if not reqid or reqid == "" then
    reqid = string.format("%x-%x", math.floor(ngx.now() * 1000), ngx.worker.pid())
  end
  ngx.ctx.request_id = reqid

  local edge_loc = os.getenv("EDGE_REGION") or "unknown"
  local ts = os.date("!%Y-%m-%dT%H:%M:%SZ")
  local client_ip = ngx.var.remote_addr or "unknown"
  local host = ngx.var.host or "unknown"
  local router_error = safe_context_value("router_error")
  local upstream_status = ngx.var.upstream_status
  local upstream_response_time = ngx.var.upstream_response_time
  local origin = type(ngx.ctx.origin) == "table" and ngx.ctx.origin or {}
  local origin_id = origin.id or ngx.var.target_origin_id
  local edge_class = code == 500 and "warn" or "ok"
  local origin_class = code == 500 and "muted" or "bad"
  local status_url = os.getenv("CDNLITE_STATUS_URL") or "/cdn-status"
  local docs_url = os.getenv("CDNLITE_DOCS_URL") or "/docs"
  local support_url = os.getenv("CDNLITE_SUPPORT_URL") or "/support"

  ngx.status = code
  ngx.header['X-CDNLITE-Request-Id'] = reqid
  identity.apply()
  ngx.header.content_type = "text/html; charset=utf-8"
  ngx.say([[
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>]] .. h(info.label) .. [[ | CDNLite</title>
  <style>
    :root{--page-bg:#f7f9fc;--panel:#fff;--panel-soft:#f9fafb;--text:#101827;--muted:#5f6b7a;--subtle:#8a95a5;--border:#dfe5ee;--brand:#155eef;--brand-dark:#0f3fa8;--success:#16803c;--warning:#b7791f;--danger:#c2410c;--shadow:0 22px 70px rgba(15,23,42,.11);--radius:14px}
    *{box-sizing:border-box}html{background:var(--page-bg);color-scheme:light}body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#ffffff 0,#f7f9fc 42%,#eef3f9 100%);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;line-height:1.5}
    .page{width:min(1040px,100%);margin:0 auto;padding:44px 18px}.shell{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}.hero{padding:30px 30px 24px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,#fff,#fbfcfe)}
    .brand{display:flex;align-items:center;gap:10px;font-weight:750;color:var(--brand-dark);letter-spacing:.01em}.mark{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;background:var(--brand);color:#fff;font-weight:800;box-shadow:0 8px 20px rgba(21,94,239,.24)}
    h1{margin:22px 0 8px;font-size:clamp(2rem,5vw,3.15rem);line-height:1.05;letter-spacing:0}.summary{max-width:720px;margin:0;color:var(--muted);font-size:1.06rem}.badge{display:inline-flex;gap:8px;align-items:center;margin-top:20px;padding:8px 12px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:999px;font-weight:720}.badge svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .content{padding:24px 30px 30px}.status-flow{display:grid;grid-template-columns:1fr 28px 1fr 28px 1fr;gap:12px;align-items:stretch}.connector{align-self:center;height:1px;background:var(--border)}.node{display:flex;gap:12px;align-items:flex-start;padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--panel-soft)}.node svg{width:24px;height:24px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}.node strong{display:block}.state{display:block;margin-top:3px;font-weight:760}.ok{color:var(--success)}.warn{color:var(--warning)}.bad{color:var(--danger)}.muted{color:var(--muted)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px}.panel{border:1px solid var(--border);border-radius:12px;background:#fff;padding:18px}.panel h2{margin:0 0 10px;font-size:1.02rem}.panel ul{margin:0;padding-left:20px;color:var(--muted)}.panel li+li{margin-top:6px}
    .details{margin-top:18px;border:1px solid var(--border);border-radius:12px;background:var(--panel-soft);padding:18px}.details h2{margin:0 0 12px;font-size:1.02rem}.details dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 18px;margin:0}.detail-row{min-width:0}.detail-row dt{color:var(--subtle);font-size:.82rem;text-transform:uppercase;letter-spacing:.05em}.detail-row dd{margin:2px 0 0;overflow-wrap:anywhere;font-weight:650;color:var(--text)}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border-radius:10px;border:1px solid var(--border);color:var(--text);background:#fff;text-decoration:none;font-weight:720}.button.primary{background:var(--brand);border-color:var(--brand);color:#fff}.footer{padding:16px 30px;border-top:1px solid var(--border);color:var(--muted);font-size:.93rem;background:#fff}
    @media (max-width:780px){.page{padding:20px 12px}.hero,.content,.footer{padding-left:18px;padding-right:18px}.status-flow{grid-template-columns:1fr}.connector{height:16px;width:1px;margin:0 auto}.grid,.details dl{grid-template-columns:1fr}h1{font-size:2rem}}
  </style>
</head>
<body>
  <main class="page">
    <section class="shell" aria-labelledby="cdnlite-error-title">
      <header class="hero">
        <div class="brand"><span class="mark">C</span><span>CDNLite</span></div>
        <h1 id="cdnlite-error-title">]] .. h(info.headline) .. [[</h1>
        <p class="summary">]] .. h(info.summary) .. [[</p>
        <div class="badge">]] .. icon("alert") .. [[<span>]] .. h(info.label) .. [[</span></div>
      </header>
      <div class="content">
        <section class="status-flow" aria-label="Connection status">
          <div class="node">]] .. icon("browser") .. [[<div><strong>User Browser</strong><span class="state ok">Working</span></div></div>
          <div class="connector" aria-hidden="true"></div>
          <div class="node">]] .. icon("edge") .. [[<div><strong>CDN Edge Server</strong><span class="state ]] .. edge_class .. [[">]] .. h(info.edge_status) .. [[</span></div></div>
          <div class="connector" aria-hidden="true"></div>
          <div class="node">]] .. icon("origin") .. [[<div><strong>Origin Server</strong><span class="state ]] .. origin_class .. [[">]] .. h(info.origin_status) .. [[</span></div></div>
        </section>
        <section class="grid">
          <div class="panel">
            <h2>For Visitors</h2>
            <ul>
              <li>Refresh the page in a moment.</li>
              <li>Try again from another network if the problem continues.</li>
              <li>Share the request ID below with the site owner.</li>
            </ul>
          </div>
          <div class="panel">
            <h2>For Site Owners</h2>
            <ul>]] .. list_items(info.owner_tips) .. [[</ul>
          </div>
        </section>
        <section class="details" aria-label="Error details">
          <h2>Error Details</h2>
          <dl>
            ]] .. detail_row("Request ID", reqid) .. [[
            ]] .. detail_row("Edge location", edge_loc) .. [[
            ]] .. detail_row("Timestamp", ts) .. [[
            ]] .. detail_row("Client IP", client_ip) .. [[
            ]] .. detail_row("Hostname", host) .. [[
            ]] .. detail_row("Router error", router_error) .. [[
            ]] .. detail_row("Upstream status", upstream_status) .. [[
            ]] .. detail_row("Upstream response time", upstream_response_time) .. [[
            ]] .. detail_row("Origin ID", origin_id) .. [[
          </dl>
        </section>
        <nav class="actions" aria-label="Help links">
          ]] .. action_link(status_url, "Check CDN Status", true) .. [[
          ]] .. action_link(docs_url, "Documentation", false) .. [[
          ]] .. action_link(support_url, "Support", false) .. [[
        </nav>
      </div>
      <footer class="footer">No secrets, origin addresses, or private configuration are included on this page.</footer>
    </section>
  </main>
</body>
</html>]])
end

return M
