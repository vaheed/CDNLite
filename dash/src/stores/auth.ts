import { defineStore } from 'pinia';
import { authApi, type AdminUser } from '@/lib/api/auth';
import { runtimeConfig } from '@/lib/config/env';
import { setAdminSessionToken } from '@/lib/auth/session';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: '',
    user: null as AdminUser | null,
    expiresAt: 0,
    loading: false,
    error: '',
  }),
  getters: {
    isAuthenticated: (state) => runtimeConfig.apiToken.trim().length > 0 || (state.token !== '' && state.user !== null),
    bearerToken: (state) => state.token || runtimeConfig.apiToken,
  },
  actions: {
    async login(username: string, password: string) {
      this.loading = true;
      this.error = '';
      try {
        const result = await authApi.login({ username, password });
        this.token = result.token;
        setAdminSessionToken(result.token);
        this.user = result.user;
        this.expiresAt = result.expires_at;
      } catch (error) {
        this.token = '';
        setAdminSessionToken('');
        this.user = null;
        this.expiresAt = 0;
        this.error = error instanceof Error ? error.message : 'Login failed';
        throw error;
      } finally {
        this.loading = false;
      }
    },
    async logout() {
      try {
        if (this.token) await authApi.logout();
      } finally {
        this.token = '';
        setAdminSessionToken('');
        this.user = null;
        this.expiresAt = 0;
        this.error = '';
      }
    },
  },
});
