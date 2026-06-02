import { api } from './client';
import type { RuntimeHealth } from '@/types';
export const healthApi = {
  coreHealth: () => api.get<RuntimeHealth>('/health', { includeAuth: false }),
  coreReady: () => api.get<RuntimeHealth>('/ready', { includeAuth: false }),
  edgeReady: () => api.get<RuntimeHealth>('/ready', { base: 'edge', includeAuth: false }),
};
