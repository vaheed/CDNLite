import { api } from './client';
import type { Recommendation } from '@/types';

export const recommendationsApi = {
  list: (domainId?: string) =>
    api.get<Recommendation[]>(domainId ? `/api/v1/domains/${domainId}/recommendations` : '/api/v1/recommendations'),
  generate: (domainId?: string) =>
    api.post<{ generated: Recommendation[]; count: number }>(domainId ? `/api/v1/domains/${domainId}/recommendations/generate` : '/api/v1/recommendations/generate'),
  apply: (domainId: string, recommendationId: string) =>
    api.post<{ recommendation: Recommendation; result: unknown }>(`/api/v1/domains/${domainId}/recommendations/${recommendationId}/apply`),
  dismiss: (domainId: string, recommendationId: string) =>
    api.post<Recommendation>(`/api/v1/domains/${domainId}/recommendations/${recommendationId}/dismiss`),
  snooze: (domainId: string, recommendationId: string, seconds = 86400) =>
    api.post<Recommendation>(`/api/v1/domains/${domainId}/recommendations/${recommendationId}/snooze`, { seconds }),
};
