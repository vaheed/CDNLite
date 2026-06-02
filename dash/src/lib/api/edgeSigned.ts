import { apiRequest, buildUrl } from './client';
import { runtimeConfig } from '@/lib/config/env';
import type { ConfigSnapshot } from '@/types';

export type SignedRequestInput = {
  method: 'GET' | 'POST';
  path: string;
  edgeId: string;
  token: string;
  body?: unknown;
};

const encoder = new TextEncoder();

export async function sha256Hex(value: string | ArrayBuffer): Promise<string> {
  const bytes = typeof value === 'string' ? encoder.encode(value) : value;
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

export async function hmacSha256Hex(keyText: string, message: string): Promise<string> {
  const key = await crypto.subtle.importKey('raw', encoder.encode(keyText), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(message));
  return [...new Uint8Array(signature)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function randomNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function pathWithoutQuery(path: string): string {
  return path.split('?')[0] || '/';
}

export async function signEdgeRequest(input: SignedRequestInput, now = Math.floor(Date.now() / 1000), nonce = randomNonce()) {
  const rawBody = input.body === undefined ? '' : JSON.stringify(input.body);
  const bodyHash = await sha256Hex(rawBody);
  const canonical = [input.method.toUpperCase(), pathWithoutQuery(input.path), String(now), nonce, bodyHash].join('\n');
  const key = await sha256Hex(input.token);
  const signature = await hmacSha256Hex(key, canonical);
  return {
    canonical,
    headers: {
      Authorization: `Bearer ${input.token}`,
      'X-CDNLITE-Edge-Id': input.edgeId,
      'X-CDNLITE-Timestamp': String(now),
      'X-CDNLITE-Nonce': nonce,
      'X-CDNLITE-Signature': signature,
    },
  };
}

async function signedRequest<T>(input: SignedRequestInput): Promise<T> {
  const signed = await signEdgeRequest(input);
  return apiRequest<T>(input.path, {
    method: input.method,
    body: input.body,
    headers: signed.headers,
    includeAuth: false,
  });
}

export const edgeSignedApi = {
  register: (edgeId: string, token: string, body: unknown) => signedRequest<unknown>({ method: 'POST', path: '/api/v1/edge/register', edgeId, token, body }),
  heartbeat: (edgeId: string, token: string, body: unknown) => signedRequest<unknown>({ method: 'POST', path: '/api/v1/edge/heartbeat', edgeId, token, body }),
  config: (edgeId: string, token: string, ifVersion?: string) => {
    const path = ifVersion ? `/api/v1/edge/config?if_version=${encodeURIComponent(ifVersion)}` : '/api/v1/edge/config';
    return signedRequest<ConfigSnapshot>({ method: 'GET', path, edgeId, token });
  },
  usage: (edgeId: string, token: string, body: unknown) => signedRequest<unknown>({ method: 'POST', path: '/api/v1/collector/usage', edgeId, token, body }),
  securityEvents: (edgeId: string, token: string, body: unknown) => signedRequest<unknown>({ method: 'POST', path: '/api/v1/collector/security-events', edgeId, token, body }),
  previewUrl: (path: string) => buildUrl(runtimeConfig.coreUrl, path),
};
