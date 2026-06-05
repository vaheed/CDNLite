import { api } from './client';
import type { CacheAnalytics, CacheRule, CacheSettings } from '@/types';
export const cacheApi = {
  settings: (domainId: string) => api.get<CacheSettings>(`/api/v1/domains/${domainId}/cache/settings`),
  updateSettings: (domainId: string, input: Partial<CacheSettings>) => api.put<CacheSettings>(`/api/v1/domains/${domainId}/cache/settings`, input),
  rules: (domainId: string) => api.get<CacheRule[]>(`/api/v1/domains/${domainId}/cache-rules`),
  createRule: (domainId: string, input: Partial<CacheRule>) => api.post<CacheRule>(`/api/v1/domains/${domainId}/cache-rules`, input),
  updateRule: (domainId: string, ruleId: string, input: Partial<CacheRule>) => api.patch<CacheRule>(`/api/v1/domains/${domainId}/cache-rules/${ruleId}`, input),
  removeRule: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/cache-rules/${ruleId}`),
  analytics: (domainId: string) => api.get<CacheAnalytics>(`/api/v1/domains/${domainId}/analytics/cache`),
};
