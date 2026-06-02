import { api } from './client';
import type { RedirectRule } from '@/types';
export const redirectsApi = {
  list: (siteId: string) => api.get<RedirectRule[]>(`/api/v1/sites/${siteId}/redirects`),
  create: (siteId: string, input: Partial<RedirectRule>) => api.post<RedirectRule>(`/api/v1/sites/${siteId}/redirects`, input),
  update: (siteId: string, redirectId: string, input: Partial<RedirectRule>) => api.patch<RedirectRule>(`/api/v1/sites/${siteId}/redirects/${redirectId}`, input),
  remove: (siteId: string, redirectId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/redirects/${redirectId}`),
  importRules: (siteId: string, input: unknown) => api.post<RedirectRule[]>(`/api/v1/sites/${siteId}/redirects/import`, input),
  exportRules: (siteId: string) => api.get<RedirectRule[]>(`/api/v1/sites/${siteId}/redirects/export`),
  test: (siteId: string, input: unknown) => api.post<unknown>(`/api/v1/sites/${siteId}/redirects/test`, input),
};
