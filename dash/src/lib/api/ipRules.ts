import { api } from './client';
import type { IpRule } from '@/types';

export const ipRulesApi = {
  list: (domainId: string) => api.get<IpRule[]>(`/api/v1/domains/${domainId}/ip-rules`),
  create: (domainId: string, input: Partial<IpRule>) => api.post<IpRule>(`/api/v1/domains/${domainId}/ip-rules`, input),
  update: (domainId: string, ruleId: string, input: Partial<IpRule>) => api.patch<IpRule>(`/api/v1/domains/${domainId}/ip-rules/${ruleId}`, input),
  remove: (domainId: string, ruleId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/ip-rules/${ruleId}`),
};
