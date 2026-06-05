import { api } from './client';
import type { PurgeRequest } from '@/types';
export const purgeApi = {
  create: (domainId: string, input: { type: string; value?: string }) => api.post<PurgeRequest>(`/api/v1/domains/${domainId}/cache/purge`, input),
  list: (domainId: string) => api.get<PurgeRequest[]>(`/api/v1/domains/${domainId}/cache/purge-requests`),
  get: (domainId: string, requestId: string) => api.get<PurgeRequest>(`/api/v1/domains/${domainId}/cache/purge-requests/${requestId}`),
};
