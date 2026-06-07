import { api } from '@/lib/api/client';
import type { ConfigSnapshot, ConfigSnapshotDiff, ConfigSnapshotSummary } from '@/types';

export const configSnapshotsApi = {
  list: () => api.get<ConfigSnapshotSummary[]>('/api/v1/config/snapshots'),
  get: (version: number) => api.get<ConfigSnapshot>(`/api/v1/config/snapshots/${version}`),
  diff: (fromVersion: number, toVersion: number) =>
    api.post<ConfigSnapshotDiff>('/api/v1/config/snapshots/diff', {
      from_version: fromVersion,
      to_version: toVersion,
    }),
  rollback: (version: number) => api.post<ConfigSnapshot>(`/api/v1/config/snapshots/${version}/rollback`),
  rebuild: () => api.post<ConfigSnapshot>('/api/v1/config/snapshots/rebuild'),
};
