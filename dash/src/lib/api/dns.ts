import { api } from './client';
import type { CreateDnsRecordInput, DnsRecord, DnsRoutingPreview, DomainDnsStatus, DomainRoutingSettings, EdgeCountry, GeoRoute, UpdateDnsRecordInput } from '@/types';
export const dnsApi = {
  list: (domainId: string) => api.get<DnsRecord[]>(`/api/v1/domains/${domainId}/dns/records`),
  status: (domainId: string) => api.get<DomainDnsStatus>(`/api/v1/domains/${domainId}/dns/status`),
  create: (domainId: string, input: CreateDnsRecordInput) => api.post<DnsRecord>(`/api/v1/domains/${domainId}/dns/records`, input),
  update: (domainId: string, recordId: string, input: UpdateDnsRecordInput) => api.patch<DnsRecord>(`/api/v1/domains/${domainId}/dns/records/${recordId}`, input),
  remove: (domainId: string, recordId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/dns/records/${recordId}`),
  reconcileRecord: (domainId: string, recordId: string) => api.post<{ record: DnsRecord | null; reconciled: boolean }>(`/api/v1/domains/${domainId}/dns/records/${recordId}/reconcile`),
  routing: (domainId: string) => api.get<DomainRoutingSettings>(`/api/v1/domains/${domainId}/routing`),
  updateRouting: (domainId: string, input: Partial<DomainRoutingSettings>) => api.patch<DomainRoutingSettings>(`/api/v1/domains/${domainId}/routing`, input),
  previewRouting: (domainId: string, recordId: string, input: UpdateDnsRecordInput = {}) => api.post<DnsRoutingPreview>(`/api/v1/domains/${domainId}/dns/records/${recordId}/preview-routing`, input),
  countries: () => api.get<EdgeCountry[]>('/api/v1/edge-countries'),
  geoRoutes: (domainId: string, recordId: string) => api.get<GeoRoute[]>(`/api/v1/domains/${domainId}/dns/records/${recordId}/geo-routes`),
  updateGeoRoutes: (domainId: string, recordId: string, routes: GeoRoute[]) => api.put<GeoRoute[]>(`/api/v1/domains/${domainId}/dns/records/${recordId}/geo-routes`, { routes }),
};
