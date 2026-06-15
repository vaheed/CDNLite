import { describe, expect, it, vi } from 'vitest';
import { DATA_INVALIDATED_EVENT, emitInvalidation } from './invalidation';
import { keysForMutation } from './queryKeys';

describe('dashboard data invalidation', () => {
  it('maps domain mutations to affected dashboard query keys', () => {
    expect(keysForMutation('POST', '/api/v1/domains/domain-1/dns/records')).toEqual(expect.arrayContaining([
      'domains',
      'domain:domain-1',
      'domain-dns:domain-1',
      'domain-origins:domain-1',
      'domain-activity:domain-1',
    ]));
    expect(keysForMutation('POST', '/api/v1/domains/domain-1/ssl/request')).toEqual(expect.arrayContaining([
      'domain-ssl:domain-1',
      'domain-activity:domain-1',
    ]));
    expect(keysForMutation('POST', '/api/v1/domains/domain-1/nameservers/verify')).toEqual(expect.arrayContaining([
      'domain:domain-1',
      'domain-dns:domain-1',
    ]));
  });

  it('emits browser invalidation events for non-GET requests', () => {
    const listener = vi.fn();
    window.addEventListener(DATA_INVALIDATED_EVENT, listener);
    emitInvalidation('PATCH', '/api/v1/domains/domain-1/origins/origin-1');
    window.removeEventListener(DATA_INVALIDATED_EVENT, listener);

    expect(listener).toHaveBeenCalledOnce();
    expect(listener.mock.calls[0][0].detail.keys).toEqual(expect.arrayContaining([
      'domain-origins:domain-1',
      'domain-activity:domain-1',
    ]));
  });
});
