<template>
  <header class="sticky top-0 z-20 border-b border-white/10 bg-slate-950/85 px-4 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-2">
        <HealthBadge :status="coreHealth?.ok ? 'ok' : 'critical'" />
        <span class="text-xs text-slate-400">Core health</span>
        <HealthBadge :status="coreReady?.ok ? 'ok' : 'warning'" />
        <span class="text-xs text-slate-400">Core ready</span>
        <HealthBadge :status="edgeReady?.ok ? 'ok' : 'warning'" />
        <span class="text-xs text-slate-400">Edge ready</span>
        <StatusBadge :status="auth.isAuthenticated ? 'enabled' : 'disabled'" :label="apiTokenConfigured ? 'API token configured' : 'Admin session active'" />
      </div>
      <div class="flex items-center gap-2 text-xs text-slate-400">
        <span>Last refresh: {{ lastRefresh }}</span>
        <button class="button-secondary px-3 py-1.5 text-xs" @click="refresh">Refresh</button>
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
const coreHealth = ref<RuntimeHealth | null>(null); const coreReady = ref<RuntimeHealth | null>(null); const edgeReady = ref<RuntimeHealth | null>(null); const refreshedAt = ref<number | null>(null);
const apiTokenConfigured = hasApiToken();
const lastRefresh = computed(() => refreshedAt.value ? formatDate(refreshedAt.value) : 'never');
async function refresh() {
  const [health, ready, edge] = await Promise.allSettled([healthApi.coreHealth(), healthApi.coreReady(), healthApi.edgeReady()]);
  coreHealth.value = health.status === 'fulfilled' ? health.value : { ok: false };
  coreReady.value = ready.status === 'fulfilled' ? ready.value : { ok: false };
  edgeReady.value = edge.status === 'fulfilled' ? edge.value : { ok: false };
  refreshedAt.value = Date.now();
}
onMounted(refresh);
</script>
