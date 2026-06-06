import { describe, expect, it, vi } from 'vitest';
import { api } from './client';
import { edgesApi } from './edges';

vi.mock('./client', () => ({ api: { get: vi.fn() } }));

describe('edgesApi', () => {
  it('loads nodes, pools, and platform DNS', async () => {
    vi.mocked(api.get).mockResolvedValue([]);
    await edgesApi.list();
    await edgesApi.pools();
    await edgesApi.dns();
    expect(api.get).toHaveBeenNthCalledWith(1, '/api/v1/edge/nodes');
    expect(api.get).toHaveBeenNthCalledWith(2, '/api/v1/edges/pools');
    expect(api.get).toHaveBeenNthCalledWith(3, '/api/v1/edges/dns');
  });
});
