import { api } from './client';
import type { UsageBucket, UsageSummary } from '@/types';
export const usageApi = {
  summary: (query?: { site_id?: string; bucket?: UsageBucket }) => api.get<UsageSummary>('/api/v1/usage/summary', { query }),
  recalculate: (siteId?: string) => api.post<{ ok: boolean }>('/api/v1/usage/recalculate', siteId ? { site_id: siteId } : {}),
};
