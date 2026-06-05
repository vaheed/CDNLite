import { api } from './client';
import type { ManualCertificateInput, SslCertificate } from '@/types';
export const sslApi = {
  certificates: (domainId: string) => api.get<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/certificates`),
  request: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/request`, input ?? {}),
  issueAcme: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/acme/issue`, input ?? {}),
  check: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/check`, input ?? {}),
  manualCertificate: (domainId: string, input: ManualCertificateInput) => api.post<SslCertificate>(`/api/v1/domains/${domainId}/ssl/manual-certificate`, input),
};
