import { api } from './client';
import type {
  ReportCache,
  ReportEdge,
  ReportOperations,
  ReportQuery,
  ReportReliability,
  ReportSecurity,
  ReportSummary,
  ReportTraffic,
} from '@/types';

function asQuery(query?: ReportQuery) {
  return query as Record<string, string | number | boolean | null | undefined> | undefined;
}

export const reportsApi = {
  summary: (query?: ReportQuery) => api.get<ReportSummary>('/api/v1/reports/summary', { query: asQuery(query) }),
  traffic: (query?: ReportQuery) => api.get<ReportTraffic>('/api/v1/reports/traffic', { query: asQuery(query) }),
  cache: (query?: ReportQuery) => api.get<ReportCache>('/api/v1/reports/cache', { query: asQuery(query) }),
  edge: (query?: ReportQuery) => api.get<ReportEdge>('/api/v1/reports/edge', { query: asQuery(query) }),
  security: (query?: ReportQuery) => api.get<ReportSecurity>('/api/v1/reports/security', { query: asQuery(query) }),
  reliability: (query?: ReportQuery) => api.get<ReportReliability>('/api/v1/reports/reliability', { query: asQuery(query) }),
  operations: (query?: ReportQuery) => api.get<ReportOperations>('/api/v1/reports/operations', { query: asQuery(query) }),
};
