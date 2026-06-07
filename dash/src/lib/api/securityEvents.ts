import type { SecurityEvent, Domain, PaginatedResult, SecuritySummary } from '@/types';
import { api } from './client';
import { wafApi } from './waf';
export async function loadSecurityEventsForDomains(domains: Domain[]): Promise<SecurityEvent[]> {
  const chunks = await Promise.allSettled(domains.map(async (domain) => {
    const events = await wafApi.events(domain.id);
    return events.map((event) => ({ ...event, domain_id: event.domain_id ?? domain.id, domain_name: domain.name }));
  }));
  return chunks.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
}

export type SecurityEventFilters = {
  domain_id?: string; edge_id?: string; type?: string; ip?: string; search?: string;
  from?: number; to?: number; limit?: number; offset?: number;
};

export const securityEventsApi = {
  list: (filters: SecurityEventFilters = {}) => api.get<PaginatedResult<SecurityEvent>>('/api/v1/security/events', { query: filters }),
  summary: (filters: Pick<SecurityEventFilters, 'from' | 'to'> = {}) => api.get<SecuritySummary>('/api/v1/security/summary', { query: filters }),
};
