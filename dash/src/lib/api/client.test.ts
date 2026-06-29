import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildUrl, apiRequest, humanizeApiError } from './client';
import type { CdnLiteApiError } from './client';
import { setAdminSessionToken } from '@/lib/auth/session';

describe('buildUrl', () => {
  afterEach(() => {
    setAdminSessionToken('');
    vi.restoreAllMocks();
  });

  it('builds paths and skips empty query values', () => {
    expect(buildUrl('http://localhost:8080/', '/api/v1/domains', { bucket: 'minute', domain_id: '' })).toBe('http://localhost:8080/api/v1/domains?bucket=minute');
  });

  it('sends the in-memory admin session bearer token', async () => {
    setAdminSessionToken('session-token');
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{"ok":true}', { status: 200 }));

    await apiRequest('/api/v1/domains');

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect((init.headers as Headers).get('Authorization')).toBe('Bearer session-token');
  });

  it('maps backend error codes to human-readable messages', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{"error":"domain_already_exists"}', { status: 409 }));

    await expect(apiRequest('/api/v1/domains')).rejects.toMatchObject({
      name: 'CdnLiteApiError',
      status: 409,
      message: 'Unable to create domain. Domain already exists.',
      code: 'domain_already_exists',
    } satisfies Partial<CdnLiteApiError>);
  });

  it('includes backend field and validation detail in errors', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
      '{"error":"invalid_field","field":"content","detail":"must_be_valid_ip_or_hostname"}',
      { status: 422 },
    ));

    await expect(apiRequest('/api/v1/domains/domain-1/dns/records')).rejects.toMatchObject({
      message: 'Content: Must be valid ip or hostname.',
      code: 'invalid_field',
    } satisfies Partial<CdnLiteApiError>);
  });

  it('humanizes unknown snake-case error codes', () => {
    expect(humanizeApiError('origin_host_required')).toBe('Origin host is required.');
    expect(humanizeApiError('custom_backend_error')).toBe('Custom backend error.');
  });

  it('humanizes DNS and origin validation errors used by domain tabs', () => {
    expect(humanizeApiError('dns_record_duplicate')).toBe('This DNS record already exists.');
    expect(humanizeApiError('dns_record_name_conflict')).toBe('This DNS name already has an incompatible CNAME or managed apex LUA record.');
    expect(humanizeApiError('dns_publish_failed')).toContain('saved locally');
    expect(humanizeApiError('must_be_80_or_443')).toBe('Port must be 80 or 443.');
    expect(humanizeApiError('must_start_with_slash')).toBe('Health check path must start with /.');
  });

  it('preserves PowerDNS publish failure context', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
      '{"error":"dns_publish_failed","detail":"powerdns_timeout","local_state_saved":true,"retry":"cdn:dns:reconcile"}',
      { status: 502 },
    ));

    await expect(apiRequest('/api/v1/domains/domain-1/dns/records', { method: 'POST' })).rejects.toMatchObject({
      message: expect.stringContaining('saved locally'),
      code: 'dns_publish_failed',
    } satisfies Partial<CdnLiteApiError>);
  });
});
