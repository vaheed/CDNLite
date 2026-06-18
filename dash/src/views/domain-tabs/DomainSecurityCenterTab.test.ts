import { fireEvent, render, waitFor } from '@testing-library/vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DomainSecurityCenterTab from './DomainSecurityCenterTab.vue';

const protectionApiMock = vi.hoisted(() => ({
  listProfiles: vi.fn(),
  previewProfile: vi.fn(),
  applyProfile: vi.fn(),
  disableProfile: vi.fn(),
  listIntents: vi.fn(),
  previewIntent: vi.fn(),
  enableIntent: vi.fn(),
  disableIntent: vi.fn(),
  undoIntent: vi.fn(),
}));

vi.mock('@/lib/api/protection', () => ({ protectionApi: protectionApiMock }));
vi.mock('@/lib/data/invalidation', () => ({ useInvalidationListener: vi.fn() }));
vi.mock('@/lib/data/queryKeys', () => ({ queryKeys: { domain: (domainId: string) => `domain:${domainId}` } }));

const availableIntents = [
  {
    intent_key: 'common_exploits',
    name: 'Common Exploit Protection',
    summary: 'Blocks high-confidence traversal and scanner patterns.',
    risk: 'safe',
    recommended_mode: 'recommended',
    status: 'available',
    intent: null,
    generated_rules: [],
  },
  {
    intent_key: 'login_shield',
    name: 'Login Shield',
    summary: 'Protects common login paths with challenge-safe rate limits.',
    risk: 'moderate',
    recommended_mode: 'recommended',
    status: 'enabled',
    intent: { id: 'intent-login', intent_key: 'login_shield', name: 'Login Shield', status: 'enabled' },
    generated_rules: [{ rule_table: 'rate_limit_rules', rule_id: 'rate-1', template_key: 'rate_login_paths', enabled: true }],
  },
  {
    intent_key: 'wordpress_hardening',
    name: 'WordPress Hardening',
    summary: 'Adds WordPress-specific XML-RPC and scanner protections.',
    risk: 'moderate',
    recommended_mode: 'recommended',
    status: 'available',
    intent: null,
    generated_rules: [],
  },
  {
    intent_key: 'bot_shield',
    name: 'Bot Shield',
    summary: 'Logs fake search bots and blocks obvious scraper user agents.',
    risk: 'moderate',
    recommended_mode: 'recommended',
    status: 'available',
    intent: null,
    generated_rules: [],
  },
  {
    intent_key: 'emergency_protection',
    name: 'Emergency Protection',
    summary: 'Temporarily tightens site-wide limits and blocks common incident scanner patterns.',
    risk: 'risky',
    recommended_mode: 'confirm_first',
    status: 'available',
    intent: null,
    generated_rules: [],
  },
];

const availableProfiles = [
  {
    profile_key: 'basic_website',
    name: 'Basic Website',
    summary: 'Safe starter protection and static asset caching for a typical site.',
    risk: 'safe',
    intent_keys: ['common_exploits', 'static_asset_performance'],
    status: 'available',
    profile: null,
  },
  {
    profile_key: 'wordpress',
    name: 'WordPress',
    summary: 'Common exploit, login, XML-RPC, scanner, bot, and static asset protections for WordPress.',
    risk: 'moderate',
    intent_keys: ['common_exploits', 'login_shield', 'wordpress_hardening', 'bot_shield', 'static_asset_performance'],
    status: 'enabled',
    profile: { id: 'profile-wordpress', profile_key: 'wordpress', name: 'WordPress', status: 'enabled', updated_at: 1710000000 },
  },
  {
    profile_key: 'emergency',
    name: 'Emergency Protection',
    summary: 'High-friction temporary controls for active attacks.',
    risk: 'risky',
    intent_keys: ['emergency_protection', 'common_exploits', 'bot_shield'],
    status: 'available',
    profile: null,
  },
];

describe('DomainSecurityCenterTab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    protectionApiMock.listProfiles.mockResolvedValue(availableProfiles);
    protectionApiMock.listIntents.mockResolvedValue(availableIntents);
    protectionApiMock.previewProfile.mockResolvedValue({
      profile_key: 'basic_website',
      name: 'Basic Website',
      risk: 'safe',
      intent_keys: ['common_exploits', 'static_asset_performance'],
      mutates: false,
      intents: [
        {
          intent_key: 'common_exploits',
          name: 'Common Exploit Protection',
          mode: 'recommended',
          risk: 'safe',
          mutates: false,
          rules: [{ rule_table: 'waf_rules', template_key: 'waf_path_traversal', payload: { action: 'block', pattern: '../' } }],
        },
      ],
    });
    protectionApiMock.applyProfile.mockResolvedValue({ profile: { id: 'profile-basic', profile_key: 'basic_website', name: 'Basic Website', status: 'enabled' }, intents: [] });
    protectionApiMock.disableProfile.mockResolvedValue({ profile: { id: 'profile-wordpress', profile_key: 'wordpress', name: 'WordPress', status: 'disabled' }, intents: [] });
    protectionApiMock.previewIntent.mockResolvedValue({
      intent_key: 'common_exploits',
      name: 'Common Exploit Protection',
      mode: 'recommended',
      risk: 'safe',
      mutates: false,
      rules: [{ rule_table: 'waf_rules', template_key: 'waf_path_traversal', payload: { action: 'block', pattern: '../' } }],
    });
    protectionApiMock.enableIntent.mockResolvedValue({ intent: { id: 'intent-common', intent_key: 'common_exploits', name: 'Common Exploit Protection', status: 'enabled' }, rules: [] });
    protectionApiMock.disableIntent.mockResolvedValue({ intent: { id: 'intent-login', intent_key: 'login_shield', name: 'Login Shield', status: 'disabled' }, rules: [] });
    protectionApiMock.undoIntent.mockResolvedValue({ intent: { id: 'intent-login', intent_key: 'login_shield', name: 'Login Shield', status: 'enabled' }, rules: [] });
  });

  it('shows beginner protection rows with safe and risky labels', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByText('Basic Website')).toBeInTheDocument());
    await waitFor(() => expect(view.getByText('Common Exploit Protection')).toBeInTheDocument());
    expect(view.getByText('Recommended setups')).toBeInTheDocument();
    expect(view.getByText('Apply a complete preset for a site type or situation.')).toBeInTheDocument();
    expect(view.getByText('Protection controls')).toBeInTheDocument();
    expect(view.getByText('Turn one specific protection on or off without applying a full setup.')).toBeInTheDocument();
    expect(view.getByText('Emergency Protection Setup')).toBeInTheDocument();
    expect(view.getByText('Emergency Protection Control')).toBeInTheDocument();
    expect(view.getAllByText('Safe').length).toBeGreaterThan(0);
    expect(view.getAllByText('Needs review').length).toBeGreaterThan(0);
    expect(view.getAllByText('2 generated rules').length).toBeGreaterThan(0);
  });

  it('shows profile bundle details and last applied status', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByText('Basic Website')).toBeInTheDocument());
    expect(view.getByText('Common Exploit Protection, Static Asset Performance')).toBeInTheDocument();
    expect(view.getByText('Common Exploit Protection, Login Shield, WordPress Hardening, Bot Shield, Static Asset Performance')).toBeInTheDocument();
    expect(view.getByText(/Last applied/)).toBeInTheDocument();
  });

  it('previews, applies, and disables one-click profiles', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByRole('button', { name: /preview basic website/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /preview basic website/i }));
    await waitFor(() => expect(protectionApiMock.previewProfile).toHaveBeenCalledWith('domain-1', 'basic_website'));
    expect(view.getByRole('dialog', { name: /basic website preview/i })).toBeInTheDocument();
    expect(view.getByText('waf_path_traversal')).toBeInTheDocument();
    expect(view.getByText('Before')).toBeInTheDocument();
    expect(view.getByText('Available profile')).toBeInTheDocument();
    expect(view.getByText('After')).toBeInTheDocument();
    expect(view.getByText('Applies 2 protection outcomes')).toBeInTheDocument();

    await fireEvent.click(view.getByRole('button', { name: /apply basic website/i }));
    expect(protectionApiMock.applyProfile).toHaveBeenCalledWith('domain-1', 'basic_website');

    await fireEvent.click(view.getByRole('button', { name: /disable wordpress/i }));
    expect(protectionApiMock.disableProfile).toHaveBeenCalledWith('domain-1', 'profile-wordpress');
  });

  it('opens WordPress preview in a modal instead of appending it to the page', async () => {
    protectionApiMock.previewProfile.mockResolvedValueOnce({
      profile_key: 'wordpress',
      name: 'WordPress',
      risk: 'moderate',
      intent_keys: ['common_exploits', 'login_shield'],
      mutates: false,
      intents: [
        {
          intent_key: 'login_shield',
          name: 'Login Shield',
          mode: 'recommended',
          risk: 'moderate',
          mutates: false,
          rules: [{ rule_table: 'rate_limit_rules', template_key: 'rate_wp_login', payload: { action: 'block', path_prefix: '/wp-login.php' } }],
        },
      ],
    });
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByRole('button', { name: /^preview wordpress$/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /^preview wordpress$/i }));

    await waitFor(() => expect(protectionApiMock.previewProfile).toHaveBeenCalledWith('domain-1', 'wordpress'));
    expect(view.getByRole('dialog', { name: /wordpress preview/i })).toBeInTheDocument();
    expect(view.getByText('rate_wp_login')).toBeInTheDocument();
  });

  it('previews and enables an available intent without mutating during preview', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByRole('button', { name: /preview common exploit protection/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /preview common exploit protection/i }));

    await waitFor(() => expect(protectionApiMock.previewIntent).toHaveBeenCalledWith('domain-1', 'common_exploits'));
    expect(view.getByRole('dialog', { name: /common exploit protection preview/i })).toBeInTheDocument();
    expect(view.getByText('waf_path_traversal')).toBeInTheDocument();

    await fireEvent.click(view.getByRole('button', { name: /enable common exploit protection/i }));
    expect(protectionApiMock.enableIntent).toHaveBeenCalledWith('domain-1', 'common_exploits');
  });

  it('disables and undoes enabled intents by intent id', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByRole('button', { name: /disable login shield/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /disable login shield/i }));
    expect(protectionApiMock.disableIntent).toHaveBeenCalledWith('domain-1', 'intent-login');

    await fireEvent.click(view.getByRole('button', { name: /undo login shield/i }));
    expect(protectionApiMock.undoIntent).toHaveBeenCalledWith('domain-1', 'intent-login');
  });
});
