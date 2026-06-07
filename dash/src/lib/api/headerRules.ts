import { api } from './client';
import type { HeaderRule } from '@/types';

export const headerRulesApi = {
  list: (domainId: string) => api.get<HeaderRule[]>(`/api/v1/domains/${domainId}/headers`),
  create: (domainId: string, input: Partial<HeaderRule>) => api.post<HeaderRule>(`/api/v1/domains/${domainId}/headers`, input),
  update: (domainId: string, ruleId: string, input: Partial<HeaderRule>) => api.patch<HeaderRule>(`/api/v1/domains/${domainId}/headers/${ruleId}`, input),
  remove: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/headers/${ruleId}`),
};
