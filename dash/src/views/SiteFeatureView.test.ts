import { render, screen } from '@testing-library/vue';
import { describe, expect, it, vi } from 'vitest';
import SiteFeatureView from './SiteFeatureView.vue';
import { featurePages } from './featurePages';
import { redirectsApi } from '@/lib/api/redirects';

vi.mock('@/lib/api/sites', () => ({ sitesApi: { list: vi.fn().mockResolvedValue([{ id: 'site-1', name: 'Main', domain: 'example.com' }]) } }));
vi.mock('@/lib/api/rateLimit', () => ({ rateLimitApi: { get: vi.fn().mockResolvedValue({ enabled: true, requests_per_minute: 120, path_prefix: '/api/', key_type: 'ip_path', action: 'block' }), save: vi.fn(), remove: vi.fn() } }));
vi.mock('@/lib/api/cache', () => ({ cacheApi: { rules: vi.fn(), settings: vi.fn(), analytics: vi.fn(), updateSettings: vi.fn() } }));
vi.mock('@/lib/api/dns', () => ({ dnsApi: { list: vi.fn(), create: vi.fn() } }));
vi.mock('@/lib/api/pageRules', () => ({ pageRulesApi: { list: vi.fn(), create: vi.fn() } }));
vi.mock('@/lib/api/purge', () => ({ purgeApi: { list: vi.fn(), create: vi.fn() } }));
vi.mock('@/lib/api/redirects', () => ({ redirectsApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() } }));
vi.mock('@/lib/api/ssl', () => ({ sslApi: { certificates: vi.fn(), manualCertificate: vi.fn() } }));
vi.mock('@/lib/api/waf', () => ({ wafApi: { list: vi.fn(), create: vi.fn() } }));

describe('SiteFeatureView rate limiting', () => {
  it('renders enabled rate limit controls', async () => {
    const feature = featurePages.find((item) => item.key === 'rate-limit');
    if (!feature) throw new Error('rate-limit feature missing');
    render(SiteFeatureView, { props: { feature } });

    expect(await screen.findByRole('button', { name: 'Save / Run Rate Limiting' })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /Enabled/i })).toBeChecked();
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
