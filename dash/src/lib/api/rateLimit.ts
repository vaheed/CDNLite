import { api } from './client';
import type { RateLimitRule } from '@/types';
export const rateLimitApi = {
  get: (domainId: string) => api.get<RateLimitRule | null>(`/api/v1/domains/${domainId}/rate-limit`),
  save: (domainId: string, input: RateLimitRule) => api.put<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limit`, input),
  remove: (domainId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/rate-limit`),
};
