import { api } from './client';
import type { UsageBucket, UsageSummary } from '@/types';
export const usageApi = {
  summary: (query?: { domain_id?: string; bucket?: UsageBucket }) => api.get<UsageSummary>('/api/v1/usage/summary', { query }),
  recalculate: (domainId?: string) => api.post<{ ok: boolean }>('/api/v1/usage/recalculate', domainId ? { domain_id: domainId } : {}),
};
