import { api } from './client';
import type { OperationsEvent, PaginatedResult, SystemJob } from '@/types';

export type OperationsFilters = {
  domain_id?: string; type?: string; status?: string; search?: string; active?: boolean;
  from?: number; to?: number; limit?: number; offset?: number;
};

export const operationsApi = {
  events: (filters: OperationsFilters = {}) => api.get<PaginatedResult<OperationsEvent>>('/api/v1/events', { query: filters }),
  jobs: (filters: OperationsFilters = {}) => api.get<PaginatedResult<SystemJob>>('/api/v1/jobs', { query: filters }),
};
