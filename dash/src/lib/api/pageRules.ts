import { api } from './client';
import type { PageRule } from '@/types';
export const pageRulesApi = {
  list: (siteId: string) => api.get<PageRule[]>(`/api/v1/sites/${siteId}/page-rules`),
  create: (siteId: string, input: Partial<PageRule>) => api.post<PageRule>(`/api/v1/sites/${siteId}/page-rules`, input),
  update: (siteId: string, ruleId: string, input: Partial<PageRule>) => api.patch<PageRule>(`/api/v1/sites/${siteId}/page-rules/${ruleId}`, input),
  remove: (siteId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/page-rules/${ruleId}`),
  test: (siteId: string, input: unknown) => api.post<unknown>(`/api/v1/sites/${siteId}/page-rules/test`, input),
};
