import { describe, expect, it, vi } from 'vitest';
import { CONFIG_PUBLISHING_EVENT, DATA_INVALIDATED_EVENT, emitInvalidation } from './invalidation';
import { notify, useNotifications } from '@/lib/ui/notifications';
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

  it('emits a publishing indicator event for domain-affecting mutations', () => {
    const listener = vi.fn();
    window.addEventListener(CONFIG_PUBLISHING_EVENT, listener);
    emitInvalidation('POST', '/api/v1/domains/domain-1/dns/records');
    window.removeEventListener(CONFIG_PUBLISHING_EVENT, listener);

    expect(listener).toHaveBeenCalledOnce();
  });

  it('stores dismissible dashboard notifications', () => {
    const { notifications, dismissNotification } = useNotifications();
    const before = notifications.value.length;
    const id = notify({ kind: 'success', title: 'Saved' }, 0);

    expect(notifications.value.length).toBe(before + 1);
    expect(notifications.value.at(-1)).toMatchObject({ id, kind: 'success', title: 'Saved' });

    dismissNotification(id);
    expect(notifications.value.find((item) => item.id === id)).toBeUndefined();
  });
});
