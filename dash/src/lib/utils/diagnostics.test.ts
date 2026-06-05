import { describe, expect, it } from 'vitest';
import { buildOpsDiagnostic, cacheEfficiency, heartbeatStatus, sslRisk } from './diagnostics';

describe('diagnostics', () => {
  it('classifies edge heartbeat, ssl, cache, and ops cards', () => {
    const now = 1710000000000;
    expect(heartbeatStatus({ edge_id: 'e1', last_heartbeat_at: now / 1000 }, now)).toBe('ok');
    expect(sslRisk({ id: 'c1', hostname: 'example.com', days_left: 10 })).toBe('warning');
    expect(cacheEfficiency({ hit_ratio: 0.49 })).toBe('low');
    expect(buildOpsDiagnostic({ domains: [{ id: 's1', name: 'Demo', domain: 'demo.local', origin_host: 'core', origin_port: 8080, proxy_enabled: true }], edges: [], usage: { total_requests: 5 } }).domains).toBe(1);
    expect(buildOpsDiagnostic({ domains: [], edges: [], usage: { requests_count: 10, bytes_in: 21709, bytes_out: 60108, records: 10 } }).totalRequests).toBe(10);
  });
});
