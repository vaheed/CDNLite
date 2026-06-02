import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SitesView from './SitesView.vue';
import { sitesApi } from '@/lib/api/sites';

vi.mock('@/lib/api/sites', () => ({ sitesApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), enableProxy: vi.fn(), disableProxy: vi.fn() } }));

const site = { id: 'site-1', name: 'Main', domain: 'example.com', origin_scheme: 'https', origin_host: 'origin.example.com', origin_port: 443, proxy_enabled: true, status: 'active' };

describe('SitesView', () => {
  beforeEach(() => {
    vi.mocked(sitesApi.list).mockResolvedValue([]);
    vi.mocked(sitesApi.create).mockResolvedValue(site);
    vi.mocked(sitesApi.update).mockResolvedValue(site);
  });

  it('renders non-focusable compact help and required asterisks for site fields', async () => {
    render(SitesView);
    expect((await screen.findAllByText('Sites')).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Name help' })).toHaveAttribute('tabindex', '-1');
    expect(screen.getAllByLabelText('required').length).toBeGreaterThan(0);
    expect(screen.getByText('Human-readable site name shown in the admin.')).toBeInTheDocument();
    expect(screen.queryByText('What this is:')).not.toBeInTheDocument();
    expect(screen.queryByText('How this works:')).not.toBeInTheDocument();
  });

  it('shows inline validation instead of alerting on invalid create', async () => {
    render(SitesView);
    await fireEvent.click(screen.getByRole('button', { name: 'Create site' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Fix the highlighted fields');
    expect(screen.getByText('Site name is required.')).toBeInTheDocument();
    expect(sitesApi.create).not.toHaveBeenCalled();
  });

  it('shows origin shield secret as required for create', async () => {
    render(SitesView);
    await fireEvent.update(screen.getByPlaceholderText('Main Website'), 'Main');
    await fireEvent.update(screen.getByPlaceholderText('example.com'), 'example.com');
    await fireEvent.update(screen.getByPlaceholderText('origin.example.com'), 'origin.example.com');
    await fireEvent.click(screen.getByRole('button', { name: 'Create site' }));

    expect(await screen.findByText('Origin shield secret is required.')).toBeInTheDocument();
  });

  it('edits an existing site with the PATCH API', async () => {
    vi.mocked(sitesApi.list).mockResolvedValue([site]);
    render(SitesView);

    await fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    await fireEvent.update(screen.getByDisplayValue('Main'), 'Main Edited');
    await fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(sitesApi.update).toHaveBeenCalledWith('site-1', expect.objectContaining({ name: 'Main Edited' })));
  });
});
