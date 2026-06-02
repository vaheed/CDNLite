import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { describe, expect, it, vi } from 'vitest';
import SiteFeatureView from './SiteFeatureView.vue';
import { featurePages } from './featurePages';
import { redirectsApi } from '@/lib/api/redirects';
import { sslApi } from '@/lib/api/ssl';

vi.mock('@/lib/api/sites', () => ({ sitesApi: { list: vi.fn().mockResolvedValue([{ id: 'site-1', name: 'Main', domain: 'example.com' }]) } }));
vi.mock('@/lib/api/rateLimit', () => ({ rateLimitApi: { get: vi.fn().mockResolvedValue({ enabled: true, requests_per_minute: 120, path_prefix: '/api/', key_type: 'ip_path', action: 'block' }), save: vi.fn(), remove: vi.fn() } }));
vi.mock('@/lib/api/cache', () => ({ cacheApi: { rules: vi.fn().mockResolvedValue([]), settings: vi.fn().mockResolvedValue({ enabled: true, default_edge_ttl_seconds: 3600, cache_query_string_mode: 'include_all' }), analytics: vi.fn().mockResolvedValue({ hit_ratio: 0 }), updateSettings: vi.fn(), createRule: vi.fn(), updateRule: vi.fn(), removeRule: vi.fn() } }));
vi.mock('@/lib/api/dns', () => ({ dnsApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn() } }));
vi.mock('@/lib/api/pageRules', () => ({ pageRulesApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn(), test: vi.fn() } }));
vi.mock('@/lib/api/purge', () => ({ purgeApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), get: vi.fn() } }));
vi.mock('@/lib/api/redirects', () => ({ redirectsApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn(), importRules: vi.fn(), exportRules: vi.fn(), test: vi.fn() } }));
vi.mock('@/lib/api/ssl', () => ({ sslApi: { certificates: vi.fn().mockResolvedValue([]), check: vi.fn(), manualCertificate: vi.fn() } }));
vi.mock('@/lib/api/waf', () => ({ wafApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn(), events: vi.fn() } }));

describe('SiteFeatureView rate limiting', () => {
  it('renders enabled rate limit controls', async () => {
    const feature = featurePages.find((item) => item.key === 'rate-limit');
    if (!feature) throw new Error('rate-limit feature missing');
    render(SiteFeatureView, { props: { feature } });

    expect(await screen.findByText('Rate Limiting Records')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save Rate Limiting' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Disable rate limit' })).not.toBeInTheDocument();
    await fireEvent.click(screen.getByRole('button', { name: 'Add Rate Limiting' }));
    expect(await screen.findByRole('button', { name: 'Save Rate Limiting' })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /Enabled/i })).toBeChecked();
  });
});

describe('SiteFeatureView SSL', () => {
  it('runs SSL check with the selected site domain by default', async () => {
    vi.mocked(sslApi.check).mockResolvedValue([]);
    const feature = featurePages.find((item) => item.key === 'ssl');
    if (!feature) throw new Error('ssl feature missing');
    render(SiteFeatureView, { props: { feature } });

    await fireEvent.click(await screen.findByRole('button', { name: 'Run SSL check' }));

    await waitFor(() => expect(sslApi.check).toHaveBeenCalledWith('site-1', { hostnames: ['example.com'] }));
  });
});

describe('SiteFeatureView redirects', () => {
  it('renders edit, enable/disable, and delete actions for redirect rows', async () => {
    vi.mocked(redirectsApi.list).mockResolvedValue([{ id: 'redirect-1', enabled: true, source_path: '/old', target_url: 'https://example.com/new', status_code: 308, priority: 10, match_type: 'exact_path', preserve_query: true }]);
    const feature = featurePages.find((item) => item.key === 'redirects');
    if (!feature) throw new Error('redirects feature missing');
    render(SiteFeatureView, { props: { feature } });

    expect(await screen.findByRole('button', { name: 'Edit' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Disable' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
  });
});

describe('SiteFeatureView purge', () => {
  it('renders purge scope as a required dropdown with a purge action', async () => {
    const feature = featurePages.find((item) => item.key === 'purge');
    if (!feature) throw new Error('purge feature missing');
    render(SiteFeatureView, { props: { feature } });

    expect(await screen.findByRole('button', { name: 'Purge cache' })).toBeInTheDocument();
    expect(screen.getByText('Purge scope')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Prefix - paths starting with value' })).toBeInTheDocument();
  });
});
