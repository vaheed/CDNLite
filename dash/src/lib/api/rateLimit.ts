import { api } from './client';
import type { RateLimitRule } from '@/types';
export const rateLimitApi = {
  get: (domainId: string) => api.get<RateLimitRule | null>(`/api/v1/domains/${domainId}/rate-limit`),
  save: (domainId: string, input: RateLimitRule) => api.put<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limit`, input),
  remove: (domainId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/rate-limit`),
  list: (domainId: string) => api.get<RateLimitRule[]>(`/api/v1/domains/${domainId}/rate-limits`),
  create: (domainId: string, input: Omit<RateLimitRule, 'id'>) => api.post<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limits`, input),
  update: (domainId: string, ruleId: string, input: Partial<RateLimitRule>) => api.patch<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limits/${ruleId}`, input),
  delete: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/rate-limits/${ruleId}`),
};
