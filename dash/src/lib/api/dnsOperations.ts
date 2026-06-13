import { api } from './client';
import type { DnsOperations, DnsZoneStatus, EdgeDnsRecord } from '@/types';

export const dnsOperationsApi = {
  status: () => api.get<DnsOperations>('/api/v1/dns/operations'),
  zones: () => api.get<DnsZoneStatus[]>('/api/v1/dns/zones'),
  desired: (zone?: string) => api.get<EdgeDnsRecord[]>(`/api/v1/dns/desired${zone ? `?zone=${encodeURIComponent(zone)}` : ''}`),
  dryRun: () => api.post<{ rrsets: EdgeDnsRecord[]; zones: number; changes: number }>('/api/v1/dns/dry-run'),
  forceSync: () => api.post<{ ok: boolean; zones?: number; changes?: number; error?: string }>('/api/v1/dns/force-sync'),
};
