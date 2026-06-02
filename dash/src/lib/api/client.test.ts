import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildUrl, apiRequest } from './client';
import { setAdminSessionToken } from '@/lib/auth/session';

describe('buildUrl', () => {
  afterEach(() => {
    setAdminSessionToken('');
    vi.restoreAllMocks();
  });

  it('builds paths and skips empty query values', () => {
    expect(buildUrl('http://localhost:8080/', '/api/v1/sites', { bucket: 'minute', site_id: '' })).toBe('http://localhost:8080/api/v1/sites?bucket=minute');
  });

  it('sends the in-memory admin session bearer token', async () => {
    setAdminSessionToken('session-token');
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{"ok":true}', { status: 200 }));

    await apiRequest('/api/v1/sites');

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect((init.headers as Headers).get('Authorization')).toBe('Bearer session-token');
  });
});
