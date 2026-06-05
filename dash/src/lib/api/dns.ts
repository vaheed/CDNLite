import { api } from './client';
import type { CreateDnsRecordInput, DnsRecord, UpdateDnsRecordInput } from '@/types';
export const dnsApi = {
  list: (domainId: string) => api.get<DnsRecord[]>(`/api/v1/domains/${domainId}/dns/records`),
  create: (domainId: string, input: CreateDnsRecordInput) => api.post<DnsRecord>(`/api/v1/domains/${domainId}/dns/records`, input),
  update: (domainId: string, recordId: string, input: UpdateDnsRecordInput) => api.patch<DnsRecord>(`/api/v1/domains/${domainId}/dns/records/${recordId}`, input),
  remove: (domainId: string, recordId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/dns/records/${recordId}`),
};
