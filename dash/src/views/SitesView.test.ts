import { render, screen } from '@testing-library/vue';
import { describe, expect, it, vi } from 'vitest';
import SitesView from './SitesView.vue';

vi.mock('@/lib/api/sites', () => ({ sitesApi: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), remove: vi.fn(), enableProxy: vi.fn(), disableProxy: vi.fn() } }));

describe('SitesView', () => {
  it('renders site form help text', async () => {
    render(SitesView);
    expect((await screen.findAllByText('Sites')).length).toBeGreaterThan(0);
    expect(screen.getByText('Human-readable site name shown in the admin.')).toBeInTheDocument();
  });
});
