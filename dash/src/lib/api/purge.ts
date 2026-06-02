import { api } from './client';
import type { PurgeRequest } from '@/types';
export const purgeApi = {
  create: (siteId: string, input: { type: string; value?: string }) => api.post<PurgeRequest>(`/api/v1/sites/${siteId}/cache/purge`, input),
  list: (siteId: string) => api.get<PurgeRequest[]>(`/api/v1/sites/${siteId}/cache/purge-requests`),
  get: (siteId: string, requestId: string) => api.get<PurgeRequest>(`/api/v1/sites/${siteId}/cache/purge-requests/${requestId}`),
};
