import { describe, expect, it, vi } from 'vitest';

vi.mock('./client', () => ({ api: { post: vi.fn() } }));
import { api } from './client';
import { domainsApi } from './domains';

describe('domainsApi onboarding', () => {
  it('uses the nameserver verification and activation endpoints', async () => {
    vi.mocked(api.post).mockResolvedValue({ id: 'domain-1' });
    await domainsApi.verifyNameservers('domain-1');
    await domainsApi.activate('domain-1', true);
    expect(api.post).toHaveBeenNthCalledWith(1, '/api/v1/domains/domain-1/verify-nameservers');
    expect(api.post).toHaveBeenNthCalledWith(2, '/api/v1/domains/domain-1/activate', { override: true });
  });
});

