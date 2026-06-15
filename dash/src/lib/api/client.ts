import { runtimeConfig } from '@/lib/config/env';
import { getAdminSessionToken } from '@/lib/auth/session';
import { emitInvalidation } from '@/lib/data/invalidation';
import type { ApiEnvelope } from '@/types';

export class CdnLiteApiError extends Error {
  status: number;
  details: unknown;
  code?: string;
  constructor(status: number, message: string, details?: unknown, code?: string) {
    super(message);
    this.name = 'CdnLiteApiError';
    this.status = status;
    this.details = details;
    this.code = code;
  }
}

type Query = Record<string, string | number | boolean | null | undefined>;
export type ApiBase = 'core' | 'edge';
export type RequestOptions = Omit<RequestInit, 'body'> & {
  base?: ApiBase;
  query?: Query;
  body?: unknown;
  timeoutMs?: number;
  includeAuth?: boolean;
};

export function buildUrl(baseUrl: string, path: string, query?: Query): string {
  const cleanBase = baseUrl.replace(/\/$/, '');
  const cleanPath = path.startsWith('/') ? path : `/${path}`;
  const url = new URL(`${cleanBase}${cleanPath}`);
  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  });
  return url.toString();
}

function redact(value: string) {
  return value.length <= 8 ? '********' : `${value.slice(0, 4)}…${value.slice(-4)}`;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const base = options.base === 'edge' ? runtimeConfig.edgeUrl : runtimeConfig.coreUrl;
  const url = buildUrl(base, path, options.query);
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), options.timeoutMs ?? runtimeConfig.requestTimeoutMs);
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  if (options.body !== undefined) headers.set('Content-Type', 'application/json');
  if (options.includeAuth !== false && options.base !== 'edge') {
    const token = getAdminSessionToken() || runtimeConfig.apiToken;
    if (token) headers.set('Authorization', `Bearer ${token}`);
  }
  try {
    if (import.meta.env.DEV) {
      const auth = headers.get('Authorization');
      console.debug('[cdnlite-api]', options.method ?? 'GET', url, auth ? { Authorization: redact(auth) } : {});
    }
    const response = await fetch(url, {
      ...options,
      headers,
      signal: controller.signal,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const text = await response.text();
    const payload = text ? safeJson(text) : null;
    if (!response.ok) {
      const raw = extractErrorCode(payload) ?? `HTTP ${response.status}`;
      throw new CdnLiteApiError(response.status, formatApiError(payload, raw), payload, raw);
    }
    const result = unwrap<T>(payload);
    emitInvalidation(options.method ?? 'GET', path);
    return result;
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new CdnLiteApiError(408, 'Request timed out');
    }
    throw error;
  } finally {
    window.clearTimeout(timeout);
  }
}

function safeJson(text: string): unknown {
  try { return JSON.parse(text); } catch { return text; }
}

function extractErrorCode(payload: unknown): string | undefined {
  if (payload && typeof payload === 'object') {
    const record = payload as Record<string, unknown>;
    const nested = record.error && typeof record.error === 'object' ? record.error as Record<string, unknown> : null;
    const value = record.code ?? nested?.code ?? nested?.message ?? record.error ?? record.message;
    return typeof value === 'string' ? value : undefined;
  }
  return typeof payload === 'string' ? payload : undefined;
}

export function humanizeApiError(code: string): string {
  const normalized = code.toLowerCase().trim();
  const messages: Record<string, string> = {
    name_required: 'Domain name is required.',
    domain_required: 'Domain is required.',
    origin_host_required: 'Origin host is required.',
    domain_already_exists: 'Unable to create domain. Domain already exists.',
    invalid_json: 'The request body contains invalid JSON.',
    internal_server_error: 'The server hit an internal error. Try again or check the core logs.',
  };
  if (messages[normalized]) return messages[normalized];
  if (/^[a-z0-9_]+$/.test(normalized)) {
    return normalized.replaceAll('_', ' ').replace(/^\w/, (char) => char.toUpperCase()) + '.';
  }
  return code;
}

function formatApiError(payload: unknown, fallback: string): string {
  if (payload && typeof payload === 'object') {
    const record = payload as Record<string, unknown>;
    if (typeof record.detail === 'string') {
      const detail = humanizeApiError(record.detail);
      return typeof record.field === 'string'
        ? `${humanizeApiError(record.field).replace(/\.$/, '')}: ${detail}`
        : detail;
    }
  }
  return humanizeApiError(fallback);
}

function unwrap<T>(payload: unknown): T {
  if (payload && typeof payload === 'object' && 'data' in payload) return (payload as ApiEnvelope<T>).data;
  return payload as T;
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => apiRequest<T>(path, { ...options, method: 'GET' }),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => apiRequest<T>(path, { ...options, method: 'POST', body }),
  patch: <T>(path: string, body?: unknown, options?: RequestOptions) => apiRequest<T>(path, { ...options, method: 'PATCH', body }),
  put: <T>(path: string, body?: unknown, options?: RequestOptions) => apiRequest<T>(path, { ...options, method: 'PUT', body }),
  delete: <T>(path: string, options?: RequestOptions) => apiRequest<T>(path, { ...options, method: 'DELETE' }),
};
