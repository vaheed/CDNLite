import { api } from './client';
import type { EdgeDnsStatus, EdgeNode, EdgePool } from '@/types';
export const edgesApi = {
  list: () => api.get<EdgeNode[]>('/api/v1/edge/nodes'),
  pools: () => api.get<EdgePool[]>('/api/v1/edges/pools'),
  dns: () => api.get<EdgeDnsStatus>('/api/v1/edges/dns'),
};
