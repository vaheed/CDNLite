import { api } from './client';
import type { CreateSiteInput, Site, UpdateSiteInput } from '@/types';
export const sitesApi = {
  list: () => api.get<Site[]>('/api/v1/sites'),
  create: (input: CreateSiteInput) => api.post<Site>('/api/v1/sites', input),
  update: (id: string, input: UpdateSiteInput) => api.patch<Site>(`/api/v1/sites/${id}`, input),
  remove: (id: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${id}`),
  enableProxy: (id: string) => api.post<Site>(`/api/v1/sites/${id}/proxy/enable`),
  disableProxy: (id: string) => api.post<Site>(`/api/v1/sites/${id}/proxy/disable`),
};
