import { api } from './client';
import type { ActivityExport, ActivitySummary, ActivityTimeline, RequestActivity, UsageBucket, UsageSummary } from '@/types';
export const usageApi = {
  summary: (query?: { domain_id?: string; bucket?: UsageBucket }) => api.get<UsageSummary>('/api/v1/usage/summary', { query }),
  domainSummary: (domainId: string, query?: { bucket?: UsageBucket }) => api.get<UsageSummary>(`/api/v1/domains/${domainId}/analytics/summary`, { query }),
  recentRequests: (domainId: string, query?: { limit?: number }) => api.get<RequestActivity[]>(`/api/v1/domains/${domainId}/activity/requests`, { query }),
  activitySummary: (domainId: string, query?: { from?: number; to?: number }) => api.get<ActivitySummary>(`/api/v1/domains/${domainId}/activity/summary`, { query }),
  activityTimeline: (domainId: string, query?: { from?: number; to?: number; type?: string; search?: string; cursor?: string; limit?: number }) => api.get<ActivityTimeline>(`/api/v1/domains/${domainId}/activity`, { query }),
  findRequest: (domainId: string, requestId: string) => api.get<RequestActivity>(`/api/v1/domains/${domainId}/activity/requests/${encodeURIComponent(requestId)}`),
  exportActivity: (domainId: string, query?: { from?: number; to?: number; type?: string; search?: string; limit?: number }) => api.get<ActivityExport>(`/api/v1/domains/${domainId}/activity/export`, { query }),
  recalculate: (domainId?: string) => api.post<{ ok: boolean }>('/api/v1/usage/recalculate', domainId ? { domain_id: domainId } : {}),
};
