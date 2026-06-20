<template>
  <div class="panel-section overflow-hidden p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-5">
      <div>
        <h3 class="text-sm font-semibold uppercase tracking-normal text-slate-700 dark:text-slate-200">Recommendations</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Proactive fixes from traffic, cache, origin, SSL, and security signals.</p>
      </div>
      <button class="button-secondary" :disabled="loading" @click="refresh(true)">Refresh</button>
    </div>
    <div v-if="error" class="state-error m-4">{{ error }}</div>
    <div v-else-if="!items.length" class="p-5 text-sm text-slate-500 dark:text-slate-400">No active recommendations.</div>
    <div v-else class="divide-y divide-slate-200 dark:divide-white/10">
      <div v-for="item in items" :key="item.id" class="grid gap-3 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,1fr)_170px_260px] lg:items-center">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <h4 class="text-base font-semibold text-slate-950 dark:text-white">{{ item.title }}</h4>
            <StatusBadge :status="riskStatus(item.risk)" :label="riskLabel(item.risk)" />
            <StatusBadge status="info" :label="`${item.confidence}% confidence`" />
          </div>
          <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-slate-400">{{ item.message }}</p>
          <details class="mt-2 text-xs text-slate-500 dark:text-slate-400">
            <summary class="cursor-pointer font-semibold text-slate-600 dark:text-slate-300">Why am I seeing this?</summary>
            <p class="mt-1 leading-5">{{ item.why }}</p>
          </details>
        </div>
        <div class="text-sm text-slate-600 dark:text-slate-300">
          <span class="font-medium capitalize">{{ item.impact }}</span>
          <span v-if="item.domain_name" class="mt-1 block text-xs text-slate-500">{{ item.domain_name }}</span>
        </div>
        <div class="grid gap-2 sm:flex sm:flex-wrap lg:justify-end">
          <button v-if="canApply(item)" class="button-primary w-full sm:w-auto" :disabled="busy === item.id" @click="apply(item)">Apply</button>
          <button class="button-secondary w-full sm:w-auto" :disabled="busy === item.id" @click="snooze(item)">Snooze</button>
          <button class="button-secondary w-full sm:w-auto" :disabled="busy === item.id" @click="dismiss(item)">Dismiss</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { recommendationsApi } from '@/lib/api/recommendations';
import type { ProtectionRisk, Recommendation, Severity } from '@/types';

const props = defineProps<{ domainId?: string }>();
const items = ref<Recommendation[]>([]);
const loading = ref(false);
const busy = ref('');
const error = ref('');

function riskStatus(risk: ProtectionRisk): Severity {
  if (risk === 'safe') return 'healthy';
  if (risk === 'risky') return 'critical';
  return 'warning';
}
function riskLabel(risk: ProtectionRisk): string {
  return risk === 'safe' ? 'Safe' : risk === 'risky' ? 'Risky' : 'Moderate';
}
function canApply(item: Recommendation): boolean {
  return ['enable_protection_intent', 'enable_static_asset_cache', 'run_origin_test'].includes(String(item.one_click_action?.kind ?? ''));
}
async function refresh(generate = false) {
  loading.value = true;
  error.value = '';
  try {
    if (generate) await recommendationsApi.generate(props.domainId);
    items.value = await recommendationsApi.list(props.domainId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not load recommendations.';
  } finally {
    loading.value = false;
  }
}
async function run(item: Recommendation, action: () => Promise<unknown>) {
  busy.value = item.id;
  try {
    await action();
    await refresh(false);
  } finally {
    busy.value = '';
  }
}
async function apply(item: Recommendation) { await run(item, () => recommendationsApi.apply(item.domain_id, item.id)); }
async function dismiss(item: Recommendation) { await run(item, () => recommendationsApi.dismiss(item.domain_id, item.id)); }
async function snooze(item: Recommendation) { await run(item, () => recommendationsApi.snooze(item.domain_id, item.id)); }

onMounted(() => refresh(false));
</script>
