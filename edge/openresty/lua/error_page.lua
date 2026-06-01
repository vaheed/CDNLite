local M = {}

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
    [502] = { label = "502 Origin Error", owner = "Origin Server: Unreachable", user = "Your browser reached our CDN, but the origin server did not respond." },
    [503] = { label = "503 Service Unavailable", owner = "Origin Server: Unavailable", user = "Your browser reached our CDN, but the origin server is temporarily unavailable." },
    [504] = { label = "504 Origin Timeout", owner = "Origin Server: Timeout", user = "Your browser reached our CDN, but the origin server timed out." },
    [500] = { label = "500 CDN Error", owner = "CDN: Degraded", user = "Your request reached our CDN, but an internal edge error occurred." },
  }
  return map[code] or { label = tostring(code) .. " Error", owner = "Origin Server: Error", user = "Your request reached our CDN, but the page could not be loaded." }
end

function M.render(code)
  local info = details(code)
  local reqid = ngx.ctx.request_id or ngx.var.request_id
  if not reqid or reqid == "" then
    reqid = string.format("%x-%x", math.floor(ngx.now() * 1000), ngx.worker.pid())
  end
  local edgeLoc = os.getenv("EDGE_REGION") or "unknown"
  local ts = os.date("!%Y-%m-%dT%H:%M:%SZ")
  local clientIp = ngx.var.remote_addr or "unknown"
  local host = ngx.var.host or "unknown"

  ngx.status = code
  ngx.header['X-CDNLITE-Request-Id'] = reqid
  ngx.header.content_type = "text/html; charset=utf-8"
  ngx.say([[
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CDNLite Error ]] .. h(code) .. [[</title>
  <style>
    :root{--bg:#f6f8fb;--panel:#fff;--text:#111827;--muted:#6b7280;--ok:#0a7a46;--warn:#b45309;--bad:#b91c1c;--line:#e5e7eb;--brand:#0f4c81}
    @media (prefers-color-scheme: dark){:root{--bg:#0c1118;--panel:#111827;--text:#e5e7eb;--muted:#9ca3af;--ok:#34d399;--warn:#f59e0b;--bad:#f87171;--line:#243041;--brand:#7dc5ff}}
    *{box-sizing:border-box}body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:linear-gradient(180deg,var(--bg),color-mix(in srgb,var(--bg),#000 4%));color:var(--text)}
    .wrap{max-width:980px;margin:0 auto;padding:32px 18px 40px}.card{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:24px}
    .brand{display:flex;gap:10px;align-items:center;color:var(--brand);font-weight:700}.logo{width:26px;height:26px;border-radius:8px;background:var(--brand);color:#fff;display:grid;place-items:center;font-size:13px}
    h1{font-size:clamp(1.7rem,4vw,2.5rem);margin:18px 0 6px}.sub{color:var(--muted);margin:0 0 22px}.err{display:inline-block;border:1px solid var(--line);padding:8px 12px;border-radius:999px;font-weight:600}
    .flow{display:grid;grid-template-columns:1fr auto 1fr auto 1fr;gap:10px;align-items:center;margin:20px 0}.node{border:1px solid var(--line);border-radius:12px;padding:12px}
    .arrow{color:var(--muted);text-align:center}.st-ok{color:var(--ok);font-weight:600}.st-warn{color:var(--warn);font-weight:600}.st-bad{color:var(--bad);font-weight:600}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.box{border:1px solid var(--line);border-radius:12px;padding:14px}.box h3{margin:0 0 8px;font-size:1rem}
    ul{margin:8px 0 0 18px;padding:0}.meta{font-size:.92rem;color:var(--muted);line-height:1.7}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-block;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:600;border:1px solid var(--line);color:var(--text)}
    .btn.primary{background:var(--brand);color:#fff;border-color:var(--brand)}
    footer{margin-top:14px;font-size:.92rem;color:var(--muted)}footer a{color:inherit}
    @media (max-width:760px){.flow{grid-template-columns:1fr}.arrow{display:none}.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <div class="brand"><span class="logo">C</span><span>CDNLite</span></div>
      <h1>Page could not be loaded</h1>
      <p class="sub">]] .. h(info.user) .. [[</p>
      <div class="err">]] .. h(info.label) .. [[</div>

      <div class="flow">
        <div class="node"><strong>User Browser</strong><br><span class="st-ok">Working</span></div>
        <div class="arrow">→</div>
        <div class="node"><strong>CDN Edge Server</strong><br><span class="]] .. ((code == 500) and "st-warn" or "st-ok") .. [[">]] .. ((code == 500) and "Degraded" or "Working") .. [[</span></div>
        <div class="arrow">→</div>
        <div class="node"><strong>Origin Server</strong><br><span class="st-bad">]] .. h(info.owner:gsub("^Origin Server:%s*", "")) .. [[</span></div>
      </div>

      <div class="grid">
        <div class="box">
          <h3>For Visitors</h3>
          <ul>
            <li>Refresh the page</li>
            <li>Try again after a short wait</li>
            <li>Contact the website owner</li>
          </ul>
        </div>
        <div class="box">
          <h3>For Website Owners</h3>
          <ul>
            <li>Check origin server</li>
            <li>Check DNS records</li>
            <li>Check firewall rules</li>
            <li>Check SSL configuration</li>
          </ul>
        </div>
      </div>

      <div class="actions">
        <a class="btn primary" href="/cdn-status">Check CDN Status</a>
        <a class="btn" href="/docs">Documentation</a>
      </div>

      <div class="meta">
        Ray ID / Request ID: ]] .. h(reqid) .. [[<br>
        Edge location: ]] .. h(edgeLoc) .. [[<br>
        Timestamp: ]] .. h(ts) .. [[<br>
        Client IP: ]] .. h(clientIp) .. [[<br>
        Hostname: ]] .. h(host) .. [[
      </div>
      <footer>
        Need help? <a href="/support">Support</a> · <a href="/docs">Documentation</a>
      </footer>
    </section>
  </main>
</body>
</html>]])
end

return M
