import { api } from './client';
import type { CreateDnsRecordInput, DnsRecord, DomainDnsStatus, GeoRoute, UpdateDnsRecordInput } from '@/types';
export const dnsApi = {
  list: (domainId: string) => api.get<DnsRecord[]>(`/api/v1/domains/${domainId}/dns/records`),
  status: (domainId: string) => api.get<DomainDnsStatus>(`/api/v1/domains/${domainId}/dns/status`),
  create: (domainId: string, input: CreateDnsRecordInput) => api.post<DnsRecord>(`/api/v1/domains/${domainId}/dns/records`, input),
  update: (domainId: string, recordId: string, input: UpdateDnsRecordInput) => api.patch<DnsRecord>(`/api/v1/domains/${domainId}/dns/records/${recordId}`, input),
  remove: (domainId: string, recordId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/dns/records/${recordId}`),
  reconcileRecord: (domainId: string, recordId: string) => api.post<{ record: DnsRecord | null; reconciled: boolean }>(`/api/v1/domains/${domainId}/dns/records/${recordId}/reconcile`),
  geoRoutes: (domainId: string, recordId: string) => api.get<GeoRoute[]>(`/api/v1/domains/${domainId}/dns/records/${recordId}/geo-routes`),
  updateGeoRoutes: (domainId: string, recordId: string, routes: GeoRoute[]) => api.put<GeoRoute[]>(`/api/v1/domains/${domainId}/dns/records/${recordId}/geo-routes`, { routes }),
};
