import { api } from './client';
import type { SecurityEvent, WafRule } from '@/types';
export const wafApi = {
  list: (domainId: string) => api.get<WafRule[]>(`/api/v1/domains/${domainId}/waf-rules`),
  create: (domainId: string, input: Partial<WafRule>) => api.post<WafRule>(`/api/v1/domains/${domainId}/waf-rules`, input),
  update: (domainId: string, wafId: string, input: Partial<WafRule>) => api.patch<WafRule>(`/api/v1/domains/${domainId}/waf-rules/${wafId}`, input),
  remove: (domainId: string, wafId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/waf-rules/${wafId}`),
  events: (domainId: string, query?: { type?: string; limit?: number }) => api.get<SecurityEvent[]>(`/api/v1/domains/${domainId}/security/events`, { query }),
};
