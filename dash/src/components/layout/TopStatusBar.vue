<template>
  <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 px-3 py-3 backdrop-blur dark:border-white/10 dark:bg-slate-950/85 sm:px-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <HealthBadge :status="coreHealth?.ok ? 'ok' : 'critical'" />
        <span class="text-slate-500 dark:text-slate-400">Core health</span>
        <HealthBadge :status="cdnHealth?.ok ? 'ok' : 'critical'" />
        <span class="text-slate-500 dark:text-slate-400">CDN health</span>
        <ReadinessBadge label="Core" :status="readiness?.core.status ?? 'warning'" @click="drawerOpen = true" />
        <ReadinessBadge label="Edge" :status="readiness?.edge.status ?? 'warning'" @click="drawerOpen = true" />
        <StatusBadge :status="auth.isAuthenticated ? 'enabled' : 'disabled'" :label="apiTokenConfigured ? 'API token configured' : 'Admin session active'" />
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
        <span class="basis-full sm:basis-auto">Last refresh: {{ lastRefresh }}</span>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="refreshAll">Refresh</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.toggleDarkMode">{{ ui.darkMode ? 'Light' : 'Dark' }}</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.commandPaletteOpen = true">Command</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="auth.logout">Logout</button>
      </div>
    </div>
    <ReadinessDrawer :open="drawerOpen" :readiness="readiness" :refreshing="isFetching" @close="drawerOpen = false" @refresh="refetch" />
  </header>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { healthApi } from '@/lib/api/health';
import { hasApiToken } from '@/lib/config/env';
import { formatDate } from '@/lib/utils/format';
import type { RuntimeHealth } from '@/types';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import HealthBadge from '@/components/ui/HealthBadge.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ReadinessBadge from '@/components/health/ReadinessBadge.vue';
import ReadinessDrawer from '@/components/health/ReadinessDrawer.vue';

const ui = useUiStore();
const auth = useAuthStore();
const coreHealth = ref<RuntimeHealth | null>(null);
const cdnHealth = ref<RuntimeHealth | null>(null);
const refreshedAt = ref<number | null>(null);
const drawerOpen = ref(false);
const apiTokenConfigured = hasApiToken();
const { data: readiness, isFetching, refetch } = useQuery({
  queryKey: ['readiness'],
  queryFn: healthApi.readiness,
  refetchInterval: 60_000,
});
const lastRefresh = computed(() => {
  const timestamp = readiness.value?.checked_at ? readiness.value.checked_at * 1000 : refreshedAt.value;
  return timestamp ? formatDate(timestamp) : 'never';
});

async function refreshHealth() {
  const [health, cdn] = await Promise.allSettled([healthApi.coreHealth(), healthApi.cdnHealth()]);
  coreHealth.value = health.status === 'fulfilled' ? health.value : { ok: false };
  cdnHealth.value = cdn.status === 'fulfilled' ? cdn.value : { ok: false };
  refreshedAt.value = Date.now();
}
async function refreshAll() {
  await Promise.all([refreshHealth(), refetch()]);
}
onMounted(refreshHealth);
</script>
