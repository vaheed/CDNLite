import { api } from './client';
import type { AcmeStatus, ManualCertificateInput, SslCertificate, SslSettings } from '@/types';
export const sslApi = {
  settings: (domainId: string) => api.get<SslSettings>(`/api/v1/domains/${domainId}/ssl`),
  updateSettings: (domainId: string, input: Partial<SslSettings>) => api.patch<SslSettings>(`/api/v1/domains/${domainId}/ssl/settings`, input),
  certificates: (domainId: string) => api.get<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/certificates`),
  request: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/request`, input ?? {}),
  issueAcme: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/acme/issue`, input ?? {}),
  requestCertificate: (domainId: string, input?: { hostnames?: string[] }) => api.post<{ status: string }>(`/api/v1/domains/${domainId}/ssl/request-cert`, input ?? {}),
  renew: (domainId: string) => api.post<{ status: string }>(`/api/v1/domains/${domainId}/ssl/renew`, {}),
  acmeStatus: (domainId: string) => api.get<AcmeStatus>(`/api/v1/domains/${domainId}/ssl/acme-status`),
  check: (domainId: string, input?: { hostnames?: string[] }) => api.post<SslCertificate[]>(`/api/v1/domains/${domainId}/ssl/check`, input ?? {}),
  manualCertificate: (domainId: string, input: ManualCertificateInput) => api.post<SslCertificate>(`/api/v1/domains/${domainId}/ssl/manual-certificate`, input),
};
