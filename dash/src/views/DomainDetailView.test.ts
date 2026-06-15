import { fireEvent, render, waitFor } from '@testing-library/vue';
import { defineComponent } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DomainNameserverVerification } from '@/types';

const baseDomain: DomainNameserverVerification = {
  id: 'domain-1',
  name: 'Example',
  domain: 'example.test',
  status: 'pending_nameserver',
  nameserver_status: 'unknown',
  expected_nameservers: [],
  observed_nameservers: [],
  matched_nameservers: [],
  missing_nameservers: [],
  checked_at: 0,
  resolver_errors: [],
};

const domainsApiMock = {
  get: vi.fn(),
  verifyNameservers: vi.fn(),
  forceVerifyNameservers: vi.fn(),
  reseedExpectedNameservers: vi.fn(),
};

vi.mock('@/lib/api/domains', () => ({ domainsApi: domainsApiMock }));
vi.mock('vue-router', () => ({
  RouterLink: defineComponent({
    props: { to: { type: [String, Object], required: true } },
    template: '<a :href="String(to)"><slot /></a>',
  }),
  useRoute: () => ({ params: { domainId: 'domain-1', tab: 'overview' } }),
  useRouter: () => ({ replace: vi.fn() }),
}));

const DomainDetailView = (await import('./DomainDetailView.vue')).default;

describe('DomainDetailView nameserver actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    domainsApiMock.get.mockResolvedValue({ ...baseDomain });
  });

  it('updates nameserver status after refresh without a page reload', async () => {
    domainsApiMock.verifyNameservers.mockResolvedValue({
      ...baseDomain,
      status: 'verified',
      nameserver_status: 'verified',
      expected_nameservers: ['ns1.example.test', 'ns2.example.test'],
      observed_nameservers: ['ns1.example.test', 'ns2.example.test'],
      matched_nameservers: ['ns1.example.test', 'ns2.example.test'],
      missing_nameservers: [],
      checked_at: 1710000000,
      resolver_errors: [],
    });

    const view = render(DomainDetailView, {
      global: {
        stubs: {
          DomainDnsTab: true,
          DomainSslTab: true,
          DomainCacheTab: true,
          DomainRedirectsTab: true,
          DomainPageRulesTab: true,
          DomainWafTab: true,
          DomainRateLimitsTab: true,
          DomainAnalyticsTab: true,
          DomainHeadersTab: true,
          DomainIpRulesTab: true,
          DomainOriginsTab: true,
          DomainActivityTab: true,
          HorizontalScrollFrame: { template: '<div><slot /></div>' },
          ReportExportButton: true,
        },
      },
    });

    await waitFor(() => expect(view.getByRole('button', { name: /refresh nameservers now/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /refresh nameservers now/i }));

    await waitFor(() => expect(view.getByText('Nameserver check completed: verified.')).toBeInTheDocument());
    expect(view.getAllByText('ns1.example.test, ns2.example.test')).toHaveLength(2);
    expect(domainsApiMock.verifyNameservers).toHaveBeenCalledWith('domain-1');
  });

  it('uses a friendly force-verify confirmation with an audit reason', async () => {
    domainsApiMock.forceVerifyNameservers.mockResolvedValue({
      ...baseDomain,
      status: 'active',
      nameserver_status: 'verified',
      expected_nameservers: ['ns1.example.test'],
      observed_nameservers: ['ns1.example.test'],
      matched_nameservers: ['ns1.example.test'],
      missing_nameservers: [],
      checked_at: 1710000000,
      resolver_errors: [],
    });

    const view = render(DomainDetailView, {
      global: {
        stubs: {
          DomainDnsTab: true,
          DomainSslTab: true,
          DomainCacheTab: true,
          DomainRedirectsTab: true,
          DomainPageRulesTab: true,
          DomainWafTab: true,
          DomainRateLimitsTab: true,
          DomainAnalyticsTab: true,
          DomainHeadersTab: true,
          DomainIpRulesTab: true,
          DomainOriginsTab: true,
          DomainActivityTab: true,
          HorizontalScrollFrame: { template: '<div><slot /></div>' },
          ReportExportButton: true,
        },
      },
    });

    await waitFor(() => expect(view.getByRole('button', { name: /force verify as admin/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /force verify as admin/i }));

    expect(view.getByRole('dialog', { name: /force verify nameservers/i })).toBeInTheDocument();
    await fireEvent.update(view.getByPlaceholderText(/registrar delegation confirmed/i), 'Verified registrar NS manually');
    await fireEvent.click(view.getByRole('button', { name: /force verify domain/i }));

    await waitFor(() => expect(domainsApiMock.forceVerifyNameservers).toHaveBeenCalledWith('domain-1', 'Verified registrar NS manually'));
    await waitFor(() => expect(view.queryByRole('dialog', { name: /force verify nameservers/i })).not.toBeInTheDocument());
  });
});
