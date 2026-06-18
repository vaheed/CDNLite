import { fireEvent, render, waitFor } from '@testing-library/vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DomainDnsTab from './DomainDnsTab.vue';

const dnsApiMock = vi.hoisted(() => ({
  list: vi.fn(),
  status: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
  reconcileRecord: vi.fn(),
}));

vi.mock('@/lib/api/dns', () => ({ dnsApi: dnsApiMock }));
vi.mock('@/lib/data/invalidation', () => ({ useInvalidationListener: vi.fn() }));
vi.mock('@/lib/data/queryKeys', () => ({ queryKeys: { domainDns: (domainId: string) => `domain-dns:${domainId}` } }));

describe('DomainDnsTab retry actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    dnsApiMock.status.mockResolvedValue({
      zone: 'example.test.',
      status: 'failed',
      last_attempt_at: 1710000000,
      last_success_at: 1709999000,
      last_error: 'powerdns_timeout',
      pending_changes: 1,
      converged: false,
    });
    dnsApiMock.list.mockResolvedValue([
      {
        id: 'record-1',
        type: 'A',
        name: 'www',
        content: '203.0.113.10',
        ttl: 300,
        proxied: true,
        origin_type: 'A',
        origin_content: '203.0.113.10',
        public_type: 'ALIAS',
        public_content: 'example.test.',
        origin_host: 'origin.example.test',
        origin_tls_verify: 'ignore',
        origin_scheme: 'http',
        origin_status: 'pending',
        geo_origins: {},
        routing_policy: 'standard',
        status: 'active',
        effective_status: 'active',
        disabled_reason: null,
        geo_routes_count: 0,
      },
    ]);
  });

  it('shows a retry sync action for each DNS row and calls the row reconcile endpoint', async () => {
    const view = render(DomainDnsTab, {
      props: { domainId: 'domain-1' },
    });

    await waitFor(() => expect(view.getByRole('button', { name: /retry sync/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /retry sync/i }));

    expect(dnsApiMock.reconcileRecord).toHaveBeenCalledWith('domain-1', 'record-1');
  });
});
