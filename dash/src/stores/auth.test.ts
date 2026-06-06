import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, expect, test, vi } from 'vitest';
import { authApi } from '@/lib/api/auth';
import { setAdminSessionToken } from '@/lib/auth/session';
import { useAuthStore } from './auth';

vi.mock('@/lib/api/auth', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/lib/api/auth')>();
  return { ...original, authApi: { ...original.authApi, me: vi.fn(), logout: vi.fn() } };
});

beforeEach(() => {
  window.sessionStorage.clear();
  setAdminSessionToken('');
  setActivePinia(createPinia());
});

test('restores a persisted admin session through the me endpoint', async () => {
  setAdminSessionToken('persisted-token');
  vi.mocked(authApi.me).mockResolvedValue({
    user: { id: 'admin-1', username: 'admin', status: 'active', session_expires_at: 123 },
  });

  const auth = useAuthStore();
  await auth.initialize();

  expect(auth.isAuthenticated).toBe(true);
  expect(auth.user?.username).toBe('admin');
  expect(window.sessionStorage.getItem('cdnlite.admin.session')).toBe('persisted-token');
});
