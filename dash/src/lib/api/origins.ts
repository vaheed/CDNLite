import { api } from './client';
import type { DomainOrigin } from '@/types';

export const originsApi = {
  list: (domainId: string) => api.get<DomainOrigin[]>(`/api/v1/domains/${domainId}/origins`),
  create: (domainId: string, input: Partial<DomainOrigin>) => api.post<DomainOrigin>(`/api/v1/domains/${domainId}/origins`, input),
  update: (domainId: string, originId: string, input: Partial<DomainOrigin>) => api.patch<DomainOrigin>(`/api/v1/domains/${domainId}/origins/${originId}`, input),
  remove: (domainId: string, originId: string) => api.delete<{ ok: boolean }>(`/api/v1/domains/${domainId}/origins/${originId}`),
  check: (domainId: string, originId: string) => api.post<DomainOrigin>(`/api/v1/domains/${domainId}/origins/${originId}/check`),
};
