<template>
  <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 px-3 py-3 backdrop-blur dark:border-white/10 dark:bg-slate-950/85 sm:px-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <HealthBadge :status="health.status.apiHealthy === 'healthy' ? 'ok' : health.status.apiHealthy" />
        <span class="text-slate-500 dark:text-slate-400">Core health</span>
        <ReadinessBadge label="Core ready" :status="coreReadiness" @click="drawerOpen = true" />
        <ReadinessBadge label="Edge ready" :status="edgeReadiness" @click="drawerOpen = true" />
        <StatusBadge :status="auth.isAuthenticated ? 'enabled' : 'disabled'" :label="apiTokenConfigured ? 'API token configured' : 'Admin session active'" />
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
        <span class="basis-full sm:basis-auto">Last refresh: {{ lastRefresh }}</span>
        <button class="button-secondary px-3 py-1.5 text-xs" :disabled="health.loading" @click="health.refresh">{{ health.loading ? 'Refreshing…' : 'Refresh' }}</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.toggleDarkMode">{{ ui.darkMode ? 'Light' : 'Dark' }}</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="ui.commandPaletteOpen = true">Command</button>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="auth.logout">Logout</button>
      </div>
    </div>
    <ReadinessDrawer :open="drawerOpen" :readiness="health.readiness ?? undefined" :refreshing="health.loading" @close="drawerOpen = false" @refresh="health.refresh" />
  </header>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { hasApiToken } from '@/lib/config/env';
import { formatDate } from '@/lib/utils/format';
import { useAuthStore } from '@/stores/auth';
import { useHealthStore } from '@/stores/health';
import { useUiStore } from '@/stores/ui';
import HealthBadge from '@/components/ui/HealthBadge.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ReadinessBadge from '@/components/health/ReadinessBadge.vue';
import ReadinessDrawer from '@/components/health/ReadinessDrawer.vue';

const ui = useUiStore();
const auth = useAuthStore();
const health = useHealthStore();
const drawerOpen = ref(false);
const apiTokenConfigured = hasApiToken();
const lastRefresh = computed(() => {
  const timestamp = health.readiness?.checked_at ? health.readiness.checked_at * 1000 : health.checkedAt;
  return timestamp ? formatDate(timestamp) : 'never';
});
const coreReadiness = computed(() => health.readiness?.core.status ?? readinessStatus(health.status.apiReady));
const edgeReadiness = computed(() => health.readiness?.edge.status ?? readinessStatus(health.status.edgeReachable));
function readinessStatus(status: string): 'ok' | 'warning' | 'error' | 'unknown' {
  if (status === 'healthy') return 'ok';
  if (status === 'critical') return 'error';
  if (status === 'warning') return 'warning';
  return 'unknown';
}
onMounted(health.refresh);
</script>
