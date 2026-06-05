import type { SecurityEvent, Domain } from '@/types';
import { wafApi } from './waf';
export async function loadSecurityEventsForDomains(domains: Domain[]): Promise<SecurityEvent[]> {
  const chunks = await Promise.allSettled(domains.map(async (domain) => {
    const events = await wafApi.events(domain.id);
    return events.map((event) => ({ ...event, domain_id: event.domain_id ?? domain.id, domain_name: domain.name }));
  }));
  return chunks.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
}
