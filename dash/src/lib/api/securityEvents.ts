import type { SecurityEvent, Site } from '@/types';
import { wafApi } from './waf';
export async function loadSecurityEventsForSites(sites: Site[]): Promise<SecurityEvent[]> {
  const chunks = await Promise.allSettled(sites.map(async (site) => {
    const events = await wafApi.events(site.id);
    return events.map((event) => ({ ...event, site_id: event.site_id ?? site.id, site_name: site.name }));
  }));
  return chunks.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
}
