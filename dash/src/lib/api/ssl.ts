import { api } from './client';
import type { ManualCertificateInput, SslCertificate } from '@/types';
export const sslApi = {
  certificates: (siteId: string) => api.get<SslCertificate[]>(`/api/v1/sites/${siteId}/ssl/certificates`),
  check: (siteId: string, input?: { hostname?: string }) => api.post<SslCertificate[]>(`/api/v1/sites/${siteId}/ssl/check`, input ?? {}),
  manualCertificate: (siteId: string, input: ManualCertificateInput) => api.post<SslCertificate>(`/api/v1/sites/${siteId}/ssl/manual-certificate`, input),
};
