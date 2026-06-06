import { describe, expect, it } from 'vitest';
import { buildOpsDiagnostic, cacheEfficiency, heartbeatStatus, mapHealthStatus, sslRisk } from './diagnostics';

describe('diagnostics', () => {
  it('classifies edge heartbeat, ssl, cache, and ops cards', () => {
    const now = 1710000000000;
    expect(heartbeatStatus({ edge_id: 'e1', last_heartbeat_at: now / 1000 }, now)).toBe('ok');
    expect(sslRisk({ id: 'c1', hostname: 'example.com', days_left: 10 })).toBe('warning');
    expect(cacheEfficiency({ hit_ratio: 0.49 })).toBe('low');
    expect(buildOpsDiagnostic({ domains: [{ id: 's1', name: 'Demo', domain: 'demo.local' }], edges: [], usage: { total_requests: 5 } }).domains).toBe(1);
    expect(buildOpsDiagnostic({ domains: [], edges: [], usage: { requests_count: 10, bytes_in: 21709, bytes_out: 60108, records: 10 } }).totalRequests).toBe(10);
  });

  it('keeps failed readiness distinct from an unhealthy or unreachable API', () => {
    const fulfilled = <T>(value: T): PromiseFulfilledResult<T> => ({ status: 'fulfilled', value });
    const rejected = (message: string): PromiseRejectedResult => ({ status: 'rejected', reason: new Error(message) });
    expect(mapHealthStatus(fulfilled({ ok: true }), fulfilled({ ok: false }), fulfilled({ ok: true }))).toMatchObject({
      apiReachable: 'healthy',
      apiHealthy: 'healthy',
      apiReady: 'warning',
      databaseReady: 'warning',
      overall: 'warning',
    });
    expect(mapHealthStatus(rejected('CORS'), rejected('CORS'), rejected('network'))).toMatchObject({
      apiReachable: 'unknown',
      apiHealthy: 'unknown',
      databaseReady: 'unknown',
      edgeReachable: 'unknown',
      overall: 'unknown',
    });
  });

  it('marks a confirmed unhealthy response as critical', () => {
    const fulfilled = <T>(value: T): PromiseFulfilledResult<T> => ({ status: 'fulfilled', value });
    const snapshot = mapHealthStatus(fulfilled({ ok: false }), fulfilled({ ok: false }), fulfilled({ ok: true }));
    expect(snapshot.apiHealthy).toBe('critical');
    expect(snapshot.overall).toBe('critical');
  });
});
