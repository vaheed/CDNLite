import { api } from './client';
import type { AuditEntry, PaginatedResult } from '@/types';

export type AuditFilters = {
  actor?: string; action?: string; resource_type?: string; domain_id?: string;
  search?: string;
  from?: number; to?: number; limit?: number; offset?: number;
};

export const auditLogApi = {
  list: (filters: AuditFilters = {}) => api.get<PaginatedResult<AuditEntry>>('/api/v1/audit', { query: filters }),
};
