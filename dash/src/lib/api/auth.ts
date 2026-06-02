import { api } from './client';

export type AdminUser = {
  id: string;
  username: string;
  display_name?: string | null;
  status: string;
  created_at?: number;
  updated_at?: number;
  session_expires_at?: number;
};

export type LoginResponse = {
  token: string;
  expires_at: number;
  user: AdminUser;
};

export const authApi = {
  login: (input: { username: string; password: string }) =>
    api.post<LoginResponse>('/api/v1/admin/login', input, { includeAuth: false }),
  me: () => api.get<{ user: AdminUser }>('/api/v1/admin/me'),
  logout: () => api.post<{ ok: boolean }>('/api/v1/admin/logout'),
};
