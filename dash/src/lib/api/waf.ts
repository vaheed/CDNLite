import { api } from './client';
import type { SecurityEvent, WafRule } from '@/types';
export const wafApi = {
  list: (siteId: string) => api.get<WafRule[]>(`/api/v1/sites/${siteId}/waf-rules`),
  create: (siteId: string, input: Partial<WafRule>) => api.post<WafRule>(`/api/v1/sites/${siteId}/waf-rules`, input),
  update: (siteId: string, wafId: string, input: Partial<WafRule>) => api.patch<WafRule>(`/api/v1/sites/${siteId}/waf-rules/${wafId}`, input),
  remove: (siteId: string, wafId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/waf-rules/${wafId}`),
  events: (siteId: string, query?: { type?: string; limit?: number }) => api.get<SecurityEvent[]>(`/api/v1/sites/${siteId}/security/events`, { query }),
};
