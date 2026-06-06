import { api } from './client';
import type { Overview, OverviewWarning } from '@/types';
export const overviewApi = {
  get: () => api.get<Overview>('/api/v1/overview'),
  warnings: () => api.get<OverviewWarning[]>('/api/v1/overview/warnings'),
};
