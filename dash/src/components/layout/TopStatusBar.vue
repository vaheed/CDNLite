<template>
  <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 shadow-[0_1px_3px_rgba(15,23,42,0.03)] backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/90 lg:px-6">
    <div class="flex flex-col gap-3 2xl:flex-row 2xl:items-center 2xl:justify-between">
      <div class="flex min-w-0 flex-wrap items-center gap-2">
        <span class="mr-1 hidden text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400 sm:inline">System status</span>
        <StatusBadge :status="coreHealth" :label="`Core: ${statusLabel(coreHealth)}`" />
        <ReadinessBadge label="Core" :status="coreReadiness" @click="drawerOpen = true" />
        <ReadinessBadge label="Edge" :status="edgeReadiness" @click="drawerOpen = true" />
        <StatusBadge :status="auth.isAuthenticated ? 'enabled' : 'disabled'" :label="apiTokenConfigured ? 'API token configured' : 'Admin session active'" />
      </div>

      <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <div class="flex items-center gap-2 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
          <Clock3 class="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
          <span>Last refresh <time>{{ lastRefresh }}</time></span>
        </div>
        <div class="hidden h-5 w-px bg-slate-200 dark:bg-white/10 sm:block" aria-hidden="true" />
        <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center" aria-label="Dashboard actions">
          <button class="status-action" :disabled="health.loading" @click="health.refresh">
            <RefreshCw :class="{ 'animate-spin': health.loading }" class="h-3.5 w-3.5" />
            {{ health.loading ? 'Refreshing' : 'Refresh' }}
          </button>
          <button class="status-action" @click="ui.toggleDarkMode">
            <Sun v-if="ui.darkMode" class="h-3.5 w-3.5" />
            <Moon v-else class="h-3.5 w-3.5" />
            {{ ui.darkMode ? 'Light' : 'Dark' }}
          </button>
          <button class="status-action" @click="ui.commandPaletteOpen = true">
            <Command class="h-3.5 w-3.5" />
            Command
          </button>
          <button class="status-action status-action-logout" @click="auth.logout">
            <LogOut class="h-3.5 w-3.5" />
            Logout
          </button>
        </div>
      </div>
    </div>
    <ReadinessDrawer :open="drawerOpen" :readiness="health.readiness ?? undefined" :refreshing="health.loading" @close="drawerOpen = false" @refresh="health.refresh" />
  </header>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Clock3, Command, LogOut, Moon, RefreshCw, Sun } from 'lucide-vue-next';
import { hasApiToken } from '@/lib/config/env';
import { formatDate } from '@/lib/utils/format';
import { useAuthStore } from '@/stores/auth';
import { useHealthStore } from '@/stores/health';
import { useUiStore } from '@/stores/ui';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ReadinessBadge from '@/components/health/ReadinessBadge.vue';
import ReadinessDrawer from '@/components/health/ReadinessDrawer.vue';

const ui = useUiStore();
const auth = useAuthStore();
const health = useHealthStore();
const drawerOpen = ref(false);
const apiTokenConfigured = hasApiToken();
const coreHealth = computed(() => health.status.apiHealthy === 'healthy' ? 'ok' : health.status.apiHealthy);
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
function statusLabel(status: string): string {
  if (status === 'ok') return 'Healthy';
  if (status === 'critical' || status === 'error') return 'Critical';
  if (status === 'warning') return 'Warning';
  return 'Unknown';
}
onMounted(health.refresh);
</script>
