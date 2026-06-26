<template>
  <section class="space-y-5">
    <div class="panel-section flex flex-wrap items-end gap-3">
      <div class="mr-auto"><h2 class="text-base font-semibold text-slate-950 dark:text-white">Traffic analytics</h2><p class="mt-1 text-sm text-slate-500">Explore delivery, bandwidth, and cache performance.</p></div>
      <label v-if="showDomainSelector" class="min-w-64 flex-1 space-y-2">
        <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Domain</span>
        <select v-model="selectedDomainId" class="input" aria-label="Domain">
          <option value="">All domains</option>
          <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }} - {{ domain.domain }}</option>
        </select>
      </label>
      <label class="min-w-40 space-y-2">
        <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Bucket</span>
        <select v-model="bucket" class="input" aria-label="Bucket">
          <option>minute</option>
          <option>hour</option>
          <option>day</option>
        </select>
      </label>
      <button class="button-primary" @click="load"><RefreshCw class="h-4 w-4" /> Refresh</button>
      <button class="button-secondary" @click="recalculate">Recalculate</button>
    </div>

    <p v-if="errorMessage" role="alert" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ errorMessage }}</p>
    <p v-if="jobMessage" class="rounded-md border border-sky-200 bg-sky-50 p-3 text-sm font-medium text-sky-800 dark:border-sky-400/30 dark:bg-sky-400/10 dark:text-sky-200">{{ jobMessage }}</p>
    <p v-if="summary?.freshness" class="text-sm text-slate-500">Fresh through {{ formatBucketTime(summary.freshness.latest_bucket_ts) }}. Query {{ summary.query_id }} returned {{ summary.point_count ?? points.length }} points.</p>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
      <div v-for="card in cards" :key="card.label" class="metric-panel">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ card.label }}</p>
        <p class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{{ card.value }}</p>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div v-for="card in cacheCards" :key="card.label" class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">{{ card.label }}</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ card.value }}</p>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <ChartCard title="Bandwidth in/out" :option="bandwidth" />
      <ChartCard title="Requests" :option="requests" />
    </div>

    <ChartCard title="Cache states" subtitle="HIT, MISS, EXPIRED, STALE, BYPASS, and UNKNOWN distribution." :option="cacheStates" />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RefreshCw } from 'lucide-vue-next';
import ChartCard from '@/components/ui/ChartCard.vue';
import { cacheApi } from '@/lib/api/cache';
import { domainsApi } from '@/lib/api/domains';
import { usageApi } from '@/lib/api/usage';
import { runtimeConfig } from '@/lib/config/env';
import { cacheAnalyticsChartData, summarizeCacheAnalytics } from '@/lib/utils/cacheAnalytics';
import { formatBytes, formatPercent } from '@/lib/utils/format';
import type { CacheAnalytics, Domain, UsageBucket, UsageSummary } from '@/types';

const props = withDefaults(defineProps<{ domainId?: string; showDomainSelector?: boolean }>(), {
  domainId: '',
  showDomainSelector: false,
});

const domains = ref<Domain[]>([]);
const selectedDomainId = ref(props.domainId);
const bucket = ref<UsageBucket>(runtimeConfig.defaultUsageBucket);
const summary = ref<UsageSummary | null>(null);
const cacheAnalytics = ref<CacheAnalytics | null>(null);
const errorMessage = ref('');
const jobMessage = ref('');
const activeJobId = ref('');
const points = computed(() => summary.value?.points ?? []);
const cacheSummary = computed(() => summarizeCacheAnalytics(cacheAnalytics.value));
const requestTotal = computed(() => summary.value?.requests_count ?? summary.value?.total_requests ?? summary.value?.requests ?? 0);
const timeLabels = computed(() => points.value.map((point) => formatBucketTime(point.bucket_ts)));
const cards = computed(() => [
  { label: 'Requests', value: requestTotal.value },
  { label: 'Bytes in', value: formatBytes(summary.value?.bytes_in) },
  { label: 'Bytes out', value: formatBytes(summary.value?.bytes_out) },
  { label: 'Records', value: summary.value?.records ?? 0 },
  { label: 'Cache hit ratio', value: formatPercent(cacheSummary.value.hitRatio) },
]);
const cacheCards = computed(() => [
  { label: 'HIT', value: cacheSummary.value.hit },
  { label: 'MISS', value: cacheSummary.value.miss },
  { label: 'BYPASS', value: cacheSummary.value.bypass },
  { label: 'UNKNOWN', value: cacheSummary.value.unknown },
]);

const bandwidth = computed(() => ({
  tooltip: { trigger: 'axis', valueFormatter: (value: unknown) => formatBytes(Number(value)) },
  legend: { data: ['Bandwidth in', 'Bandwidth out'] },
  grid: { left: 72, right: 24, bottom: 56, top: 44 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value', axisLabel: { formatter: (value: number) => formatBytes(value) } },
  series: [
    { name: 'Bandwidth in', type: 'line', smooth: true, showSymbol: points.value.length < 40, data: points.value.map((point) => point.bytes_in) },
    { name: 'Bandwidth out', type: 'line', smooth: true, showSymbol: points.value.length < 40, data: points.value.map((point) => point.bytes_out) },
  ],
}));
const requests = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 64, right: 24, bottom: 56, top: 24 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value' },
  series: [{ name: 'Requests', type: 'bar', data: points.value.map((point) => point.requests_count) }],
}));
const cacheStates = computed(() => ({
  tooltip: {},
  legend: {},
  series: [{ type: 'pie', radius: ['45%', '70%'], data: cacheAnalyticsChartData(cacheAnalytics.value) }],
}));

async function load() {
  errorMessage.value = '';
  const domainId = selectedDomainId.value;
  try {
    const [usage, cache] = await Promise.all([
      domainId ? usageApi.domainSummary(domainId, { bucket: bucket.value }) : usageApi.summary({ bucket: bucket.value }),
      cacheApi.analytics(domainId || undefined),
    ]);
    summary.value = usage;
    cacheAnalytics.value = cache;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to load analytics.';
  }
}

async function recalculate() {
  errorMessage.value = '';
  jobMessage.value = '';
  activeJobId.value = '';
  try {
    const job = await usageApi.recalculate(selectedDomainId.value || undefined, bucket.value);
    activeJobId.value = job.job_id;
    jobMessage.value = job.job_status === 'succeeded'
      ? `Recalculation ${job.job_id} completed. Refreshing analytics.`
      : `Recalculation queued as ${job.job_id}. Analytics stay available while aggregates refresh.`;
    if (job.job_status === 'succeeded') {
      await load();
      return;
    }
    await pollRecalculation(job.job_id);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to queue analytics recalculation.';
  }
}

async function pollRecalculation(jobId: string) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    await wait(1500);
    if (activeJobId.value !== jobId) return;
    const job = await usageApi.recalculateJob(jobId);
    if (job.status === 'succeeded') {
      jobMessage.value = `Recalculation ${jobId} completed. Analytics refreshed.`;
      await load();
      return;
    }
    if (job.status === 'failed' || job.status === 'cancelled') {
      errorMessage.value = job.error || `Recalculation ${job.status}.`;
      jobMessage.value = '';
      return;
    }
    jobMessage.value = `Recalculation ${jobId} is ${job.status}. Analytics stay available while aggregates refresh.`;
  }
  jobMessage.value = `Recalculation ${jobId} is still running. Use Refresh in a moment to reload analytics.`;
}

function wait(ms: number) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function formatBucketTime(timestamp: number) {
  const date = new Date(timestamp * 1000);
  if (bucket.value === 'day') return date.toLocaleDateString();
  if (bucket.value === 'hour') return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit' });
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

watch(() => props.domainId, (value) => { selectedDomainId.value = value; });
watch([selectedDomainId, bucket], load);
onMounted(async () => {
  if (props.showDomainSelector) {
    try {
      domains.value = await domainsApi.list();
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : 'Unable to load domains.';
    }
  }
  await load();
});
</script>
