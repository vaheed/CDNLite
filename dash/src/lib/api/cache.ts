import { api } from './client';
import type { CacheAnalytics, CacheRule, CacheSettings } from '@/types';
export const cacheApi = {
  settings: (siteId: string) => api.get<CacheSettings>(`/api/v1/sites/${siteId}/cache/settings`),
  updateSettings: (siteId: string, input: CacheSettings) => api.put<CacheSettings>(`/api/v1/sites/${siteId}/cache/settings`, input),
  rules: (siteId: string) => api.get<CacheRule[]>(`/api/v1/sites/${siteId}/cache-rules`),
  createRule: (siteId: string, input: Partial<CacheRule>) => api.post<CacheRule>(`/api/v1/sites/${siteId}/cache-rules`, input),
  updateRule: (siteId: string, ruleId: string, input: Partial<CacheRule>) => api.patch<CacheRule>(`/api/v1/sites/${siteId}/cache-rules/${ruleId}`, input),
  removeRule: (siteId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/cache-rules/${ruleId}`),
  analytics: (siteId: string) => api.get<CacheAnalytics>(`/api/v1/sites/${siteId}/analytics/cache`),
};
