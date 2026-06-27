import { api } from './client';
import type { WaitingRoomPolicy } from '@/types';

export const waitingRoomApi = {
  get: (domainId: string) => api.get<WaitingRoomPolicy>(`/api/v1/domains/${domainId}/waiting-room`),
  update: (domainId: string, input: Partial<WaitingRoomPolicy>) => api.patch<WaitingRoomPolicy>(`/api/v1/domains/${domainId}/waiting-room`, input),
  activateEmergency: (domainId: string, input: { ttl_seconds: number; reason?: string }) =>
    api.post<WaitingRoomPolicy>(`/api/v1/domains/${domainId}/waiting-room/emergency/activate`, input),
  deactivateEmergency: (domainId: string) =>
    api.post<WaitingRoomPolicy>(`/api/v1/domains/${domainId}/waiting-room/emergency/deactivate`),
};
