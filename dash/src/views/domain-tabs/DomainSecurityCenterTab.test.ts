import { fireEvent, render, waitFor } from '@testing-library/vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DomainSecurityCenterTab from './DomainSecurityCenterTab.vue';

const protectionApiMock = vi.hoisted(() => ({
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
];

describe('DomainSecurityCenterTab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    protectionApiMock.listIntents.mockResolvedValue(availableIntents);
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

  it('shows beginner protection cards with safe and risky labels', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByText('Common Exploit Protection')).toBeInTheDocument());
    expect(view.getByText('Safe')).toBeInTheDocument();
    expect(view.getByText('Needs review')).toBeInTheDocument();
    expect(view.getByText('2 generated rules')).toBeInTheDocument();
  });

  it('previews and enables an available intent without mutating during preview', async () => {
    const view = render(DomainSecurityCenterTab, { props: { domainId: 'domain-1' } });

    await waitFor(() => expect(view.getByRole('button', { name: /preview common exploit protection/i })).toBeInTheDocument());
    await fireEvent.click(view.getByRole('button', { name: /preview common exploit protection/i }));

    await waitFor(() => expect(protectionApiMock.previewIntent).toHaveBeenCalledWith('domain-1', 'common_exploits'));
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
