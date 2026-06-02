import { api } from './client';
import type { RateLimitRule } from '@/types';
export const rateLimitApi = {
  get: (siteId: string) => api.get<RateLimitRule | null>(`/api/v1/sites/${siteId}/rate-limit`),
  save: (siteId: string, input: RateLimitRule) => api.put<RateLimitRule>(`/api/v1/sites/${siteId}/rate-limit`, input),
  remove: (siteId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/rate-limit`),
};
