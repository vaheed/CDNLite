import { api } from './client';
import type { CreateDnsRecordInput, DnsRecord, UpdateDnsRecordInput } from '@/types';
export const dnsApi = {
  list: (siteId: string) => api.get<DnsRecord[]>(`/api/v1/sites/${siteId}/dns/records`),
  create: (siteId: string, input: CreateDnsRecordInput) => api.post<DnsRecord>(`/api/v1/sites/${siteId}/dns/records`, input),
  update: (siteId: string, recordId: string, input: UpdateDnsRecordInput) => api.patch<DnsRecord>(`/api/v1/sites/${siteId}/dns/records/${recordId}`, input),
  remove: (siteId: string, recordId: string) => api.delete<{ ok: boolean }>(`/api/v1/sites/${siteId}/dns/records/${recordId}`),
};
