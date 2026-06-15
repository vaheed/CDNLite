import { api } from './client';
import type { CreateDomainInput, Domain, DomainNameserverVerification, UpdateDomainInput } from '@/types';
export const domainsApi = {
  list: () => api.get<Domain[]>('/api/v1/domains'),
  get: (id: string) => api.get<Domain>(`/api/v1/domains/${id}`),
  create: (input: CreateDomainInput) => api.post<Domain>('/api/v1/domains', input),
  verifyNameservers: (id: string) => api.post<DomainNameserverVerification>(`/api/v1/domains/${id}/nameservers/verify`),
  forceVerifyNameservers: (id: string, reason: string) => api.post<DomainNameserverVerification>(`/api/v1/domains/${id}/nameservers/force-verify`, { reason }),
  reseedExpectedNameservers: (id: string) => api.post<DomainNameserverVerification>(`/api/v1/domains/${id}/nameservers/reseed-expected`),
  activate: (id: string, override = false) => api.post<Domain>(`/api/v1/domains/${id}/activate`, { override }),
  update: (id: string, input: UpdateDomainInput) => api.patch<Domain>(`/api/v1/domains/${id}`, input),
  remove: (id: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${id}`),
};
