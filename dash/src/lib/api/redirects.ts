import { api } from './client';
import type { RedirectRule } from '@/types';
export const redirectsApi = {
  list: (domainId: string) => api.get<RedirectRule[]>(`/api/v1/domains/${domainId}/redirects`),
  create: (domainId: string, input: Partial<RedirectRule>) => api.post<RedirectRule>(`/api/v1/domains/${domainId}/redirects`, input),
  update: (domainId: string, redirectId: string, input: Partial<RedirectRule>) => api.patch<RedirectRule>(`/api/v1/domains/${domainId}/redirects/${redirectId}`, input),
  remove: (domainId: string, redirectId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/redirects/${redirectId}`),
  importRules: (domainId: string, input: unknown) => api.post<RedirectRule[]>(`/api/v1/domains/${domainId}/redirects/import`, input),
  exportRules: (domainId: string) => api.get<RedirectRule[]>(`/api/v1/domains/${domainId}/redirects/export`),
  test: (domainId: string, input: unknown) => api.post<unknown>(`/api/v1/domains/${domainId}/redirects/test`, input),
};
