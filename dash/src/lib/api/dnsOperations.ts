import { api } from './client';
import type { DnsDesiredRun, DnsOperations, DnsZoneStatus, EdgeDnsRecord } from '@/types';

export const dnsOperationsApi = {
  status: () => api.get<DnsOperations>('/api/v1/dns/operations'),
  zones: () => api.get<DnsZoneStatus[]>('/api/v1/dns/zones'),
  desired: (zone?: string) => api.get<EdgeDnsRecord[]>(`/api/v1/dns/desired${zone ? `?zone=${encodeURIComponent(zone)}` : ''}`),
  dryRun: () => api.post<DnsDesiredRun>('/api/v1/dns/dry-run'),
  forceSync: () => api.post<DnsDesiredRun>('/api/v1/dns/force-sync'),
};
