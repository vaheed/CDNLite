import { api } from './client';
import type { ReadinessResponse, RuntimeHealth } from '@/types';
export const healthApi = {
  coreHealth: () => api.get<RuntimeHealth>('/health', { includeAuth: false }),
  cdnHealth: () => api.get<RuntimeHealth>('/cdn-health', { includeAuth: false }),
  coreReady: () => api.get<RuntimeHealth>('/ready', { includeAuth: false }),
  edgeReady: () => api.get<RuntimeHealth>('/ready', { base: 'edge', includeAuth: false }),
  readiness: () => api.get<ReadinessResponse>('/api/v1/readiness'),
};
