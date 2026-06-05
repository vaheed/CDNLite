import { api } from './client';
import type { PageRule } from '@/types';
export const pageRulesApi = {
  list: (domainId: string) => api.get<PageRule[]>(`/api/v1/domains/${domainId}/page-rules`),
  create: (domainId: string, input: Partial<PageRule>) => api.post<PageRule>(`/api/v1/domains/${domainId}/page-rules`, input),
  update: (domainId: string, ruleId: string, input: Partial<PageRule>) => api.patch<PageRule>(`/api/v1/domains/${domainId}/page-rules/${ruleId}`, input),
  remove: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/page-rules/${ruleId}`),
  test: (domainId: string, input: unknown) => api.post<unknown>(`/api/v1/domains/${domainId}/page-rules/test`, input),
};
