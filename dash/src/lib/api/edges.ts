import { api } from './client';
import type { EdgeNode } from '@/types';
export const edgesApi = { list: () => api.get<EdgeNode[]>('/api/v1/edge/nodes') };
