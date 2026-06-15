import { api } from './client';
import type { RequestActivity, UsageBucket, UsageSummary } from '@/types';
export const usageApi = {
  summary: (query?: { domain_id?: string; bucket?: UsageBucket }) => api.get<UsageSummary>('/api/v1/usage/summary', { query }),
  domainSummary: (domainId: string, query?: { bucket?: UsageBucket }) => api.get<UsageSummary>(`/api/v1/domains/${domainId}/analytics/summary`, { query }),
  recentRequests: (domainId: string, query?: { limit?: number }) => api.get<RequestActivity[]>(`/api/v1/domains/${domainId}/activity/requests`, { query }),
  recalculate: (domainId?: string) => api.post<{ ok: boolean }>('/api/v1/usage/recalculate', domainId ? { domain_id: domainId } : {}),
};
