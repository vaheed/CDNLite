import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { healthApi } from '@/lib/api/health';
import { mapHealthStatus } from '@/lib/utils/diagnostics';
import type { ReadinessResponse } from '@/types';

export const useHealthStore = defineStore('health', () => {
  const loading = ref(false);
  const checkedAt = ref<number | null>(null);
  const readiness = ref<ReadinessResponse | null>(null);
  const raw = ref<{
    coreHealth: PromiseSettledResult<{ ok?: boolean; ready?: boolean; error?: string }>;
    coreReady: PromiseSettledResult<{ ok?: boolean; ready?: boolean; error?: string }>;
    edgeReady: PromiseSettledResult<{ ok?: boolean; ready?: boolean; error?: string }>;
  } | null>(null);
  const status = computed(() => raw.value
    ? mapHealthStatus(raw.value.coreHealth, raw.value.coreReady, raw.value.edgeReady)
    : { apiReachable: 'unknown', apiHealthy: 'unknown', apiReady: 'unknown', databaseReady: 'unknown', edgeReachable: 'unknown', overall: 'unknown' } as const);

  async function refresh() {
    loading.value = true;
    const [coreHealth, coreReady, edgeReady, readinessResult] = await Promise.all([
      Promise.allSettled([healthApi.coreHealth()]).then(([result]) => result),
      Promise.allSettled([healthApi.coreReady()]).then(([result]) => result),
      Promise.allSettled([healthApi.edgeReady()]).then(([result]) => result),
      Promise.allSettled([healthApi.readiness()]).then(([result]) => result),
    ]);
    raw.value = { coreHealth, coreReady, edgeReady };
    readiness.value = readinessResult.status === 'fulfilled' ? readinessResult.value : null;
    checkedAt.value = Date.now();
    loading.value = false;
    return raw.value;
  }

  return { loading, checkedAt, readiness, raw, status, refresh };
});
