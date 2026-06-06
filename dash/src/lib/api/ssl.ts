import { api } from './client';
import type { ManualCertificateInput, SslCertificate, SslSettings } from '@/types';
export const sslApi = {
  settings: (domainId: string) => api.get<SslSettings>(`/api/v1/domains/${domainId}/ssl`),
  updateSettings: (domainId: string, input: Partial<SslSettings>) => api.patch<SslSettings>(`/api/v1/domains/${domainId}/ssl/settings`, input),
  certificates: (domainId: string) => api.get<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/certificates`),
  request: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/request`, input ?? {}),
  issueAcme: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/acme/issue`, input ?? {}),
  check: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/check`, input ?? {}),
  manualCertificate: (domainId: string, input: ManualCertificateInput) => api.post<SslCertificate>(`/api/v1/domains/${domainId}/ssl/manual-certificate`, input),
};
