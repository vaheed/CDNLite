<template>
  <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 px-3 py-3 backdrop-blur dark:border-white/10 dark:bg-slate-950/85 sm:px-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      <div class="grid grid-cols-2 gap-2 text-xs sm:flex sm:flex-wrap sm:items-center">
        <HealthBadge :status="coreHealth?.ok ? 'ok' : 'critical'" />
        <span class="text-xs text-slate-500 dark:text-slate-400">Core health</span>
        <HealthBadge :status="cdnHealth?.ok ? 'ok' : 'critical'" />
        <span class="text-xs text-slate-500 dark:text-slate-400">CDN health</span>
        <HealthBadge :status="coreReady?.ok ? 'ok' : 'warning'" />
        <span class="text-xs text-slate-500 dark:text-slate-400">Core ready</span>
        <HealthBadge :status="edgeReady?.ok ? 'ok' : 'warning'" />
        <span class="text-xs text-slate-500 dark:text-slate-400">Edge ready</span>
      <StatusBadge :status="auth.isAuthenticated ? 'enabled' : 'disabled'" :label="apiTokenConfigured ? 'API token configured' : 'Admin session active'" />
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
        <span class="basis-full sm:basis-auto">Last refresh: {{ lastRefresh }}</span>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="refresh">Refresh</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.toggleDarkMode">{{ ui.darkMode ? 'Light' : 'Dark' }}</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.commandPaletteOpen = true">⌘K</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="auth.logout">Logout</button>
      </div>
    </div>
  </header>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { healthApi } from '@/lib/api/health';
import { hasApiToken } from '@/lib/config/env';
import { formatDate } from '@/lib/utils/format';
import type { RuntimeHealth } from '@/types';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import HealthBadge from '@/components/ui/HealthBadge.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
const ui = useUiStore();
const auth = useAuthStore();
const coreHealth = ref<RuntimeHealth | null>(null); const cdnHealth = ref<RuntimeHealth | null>(null); const coreReady = ref<RuntimeHealth | null>(null); const edgeReady = ref<RuntimeHealth | null>(null); const refreshedAt = ref<number | null>(null);
const apiTokenConfigured = hasApiToken();
const lastRefresh = computed(() => refreshedAt.value ? formatDate(refreshedAt.value) : 'never');
async function refresh() {
  const [health, cdn, ready, edge] = await Promise.allSettled([healthApi.coreHealth(), healthApi.cdnHealth(), healthApi.coreReady(), healthApi.edgeReady()]);
  coreHealth.value = health.status === 'fulfilled' ? health.value : { ok: false };
  cdnHealth.value = cdn.status === 'fulfilled' ? cdn.value : { ok: false };
  coreReady.value = ready.status === 'fulfilled' ? ready.value : { ok: false };
  edgeReady.value = edge.status === 'fulfilled' ? edge.value : { ok: false };
  refreshedAt.value = Date.now();
}
onMounted(refresh);
</script>
