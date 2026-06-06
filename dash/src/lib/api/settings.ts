import { api } from './client';
import type { SettingsGroup, SettingsIndex, SettingsValidation } from '@/types';

export const settingsApi = {
  list: () => api.get<SettingsIndex>('/api/v1/settings'),
  group: (group: string) => api.get<SettingsGroup>(`/api/v1/settings/${group}`),
  update: (group: string, values: Record<string, unknown>) =>
    api.patch<SettingsGroup>(`/api/v1/settings/${group}`, { values }),
  validate: (group: string, values: Record<string, unknown>) =>
    api.post<SettingsValidation>('/api/v1/settings/validate', { group, values }),
  testPowerDns: () => api.post<{ ok: boolean; status: number; error?: string | null }>('/api/v1/settings/test/powerdns'),
};
