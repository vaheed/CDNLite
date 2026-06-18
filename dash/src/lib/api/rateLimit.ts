import { api } from './client';
import type { RateLimitRule } from '@/types';
export const rateLimitApi = {
  list: (domainId: string) => api.get<RateLimitRule[]>(`/api/v1/domains/${domainId}/rate-limits`),
  create: (domainId: string, input: Omit<RateLimitRule, 'id'>) => api.post<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limits`, input),
  update: (domainId: string, ruleId: string, input: Partial<RateLimitRule>) => api.patch<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limits/${ruleId}`, input),
  detachManaged: (domainId: string, ruleId: string) => api.post<RateLimitRule>(`/api/v1/domains/${domainId}/rate-limits/${ruleId}/detach-managed`),
  delete: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/rate-limits/${ruleId}`),
};
