#!/usr/bin/env python3
import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import unquote

API_KEY = os.getenv("PDNS_API_KEY", "test-key")
HOST = os.getenv("PDNS_HOST", "0.0.0.0")
PORT = int(os.getenv("PDNS_PORT", "8081"))
STATE = {"zones": {}, "requests": []}


def json_bytes(payload):
    return json.dumps(payload, separators=(",", ":"), sort_keys=True).encode()


class Handler(BaseHTTPRequestHandler):
    def _auth_ok(self):
        return self.headers.get("X-API-Key", "") == API_KEY

    def _send(self, code, payload):
        body = json_bytes(payload)
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _record(self, method, body):
        STATE["requests"].append(
            {
                "method": method,
                "path": self.path,
                "body": body,
            }
        )

    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        if self.path == "/health":
            self._send(200, {"ok": True})
            return

        if self.path.startswith("/api/v1/domains"):
            self._send(500, {"error": "forced_origin_failure"})
            return

        if self.path == "/debug/requests":
            self._send(200, {"data": STATE["requests"]})
            return

        if not self._auth_ok():
            self._send(403, {"error": "forbidden"})
            return

        if self.path.startswith("/api/v1/servers/localhost/zones/"):
            zone = unquote(self.path.rsplit("/", 1)[-1])
            zone_data = STATE["zones"].get(zone, {"id": zone, "name": zone, "rrsets": []})
            self._send(200, zone_data)
            return

        self._send(404, {"error": "not_found"})

    def do_PATCH(self):
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length).decode() if length else "{}"
        payload = json.loads(raw or "{}")
        self._record("PATCH", payload)

        if not self._auth_ok():
            self._send(403, {"error": "forbidden"})
            return

        if not self.path.startswith("/api/v1/servers/localhost/zones/"):
            self._send(404, {"error": "not_found"})
            return

        zone = unquote(self.path.rsplit("/", 1)[-1])
        current = STATE["zones"].setdefault(zone, {"id": zone, "name": zone, "rrsets": []})
        by_key = {(r["name"], r["type"]): r for r in current["rrsets"]}
        for rrset in payload.get("rrsets", []):
            key = (rrset["name"], rrset["type"])
            if rrset.get("changetype") == "DELETE":
                by_key.pop(key, None)
            else:
                by_key[key] = {
                    "name": rrset["name"],
                    "type": rrset["type"],
                    "ttl": rrset.get("ttl", 300),
                    "records": rrset.get("records", []),
                }
        current["rrsets"] = list(by_key.values())
        self.send_response(204)
        self.end_headers()


if __name__ == "__main__":
    HTTPServer((HOST, PORT), Handler).serve_forever()
