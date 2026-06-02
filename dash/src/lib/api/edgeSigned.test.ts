import { describe, expect, it } from 'vitest';
import { pathWithoutQuery, sha256Hex, signEdgeRequest } from './edgeSigned';

describe('edge signed auth', () => {
  it('hashes and builds canonical signed headers', async () => {
    expect(await sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    expect(pathWithoutQuery('/api/v1/edge/config?if_version=1')).toBe('/api/v1/edge/config');
    const signed = await signEdgeRequest({ method: 'GET', path: '/api/v1/edge/config?if_version=1', edgeId: 'edge-local-1', token: 'token' }, 1710000000, 'nonce');
    expect(signed.canonical).toContain('GET\n/api/v1/edge/config\n1710000000\nnonce');
    expect(signed.headers['X-CDNLITE-Signature']).toMatch(/^[a-f0-9]{64}$/);
  });
});
