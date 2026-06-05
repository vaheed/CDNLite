<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">Usage Analytics</h1>
      <p class="text-slate-600 dark:text-slate-400">Requests, bytes in/out, buckets, cache states, and recalculation tools.</p>
    </div>

    <div class="card flex flex-wrap gap-3 p-5">
      <select v-model="bucket" class="input max-w-xs">
        <option>minute</option>
        <option>hour</option>
        <option>day</option>
      </select>
      <button class="button-primary" @click="load">Refresh</button>
      <button class="button-secondary" @click="recalculate">Recalculate</button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">Requests</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ requestTotal }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">Bytes in</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ formatBytes(summary?.bytes_in) }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">Bytes out</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ formatBytes(summary?.bytes_out) }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">Records</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ summary?.records ?? 0 }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">Cache hit ratio</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ formatPercent(cacheSummary.hitRatio) }}</p>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">HIT</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ cacheSummary.hit }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">MISS</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ cacheSummary.miss }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">BYPASS</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ cacheSummary.bypass }}</p>
      </div>
      <div class="card p-4">
        <p class="text-slate-500 dark:text-slate-400">UNKNOWN</p>
        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ cacheSummary.unknown }}</p>
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
import { computed, onMounted, ref } from 'vue';
import ChartCard from '@/components/ui/ChartCard.vue';
import { runtimeConfig } from '@/lib/config/env';
import { cacheApi } from '@/lib/api/cache';
import { usageApi } from '@/lib/api/usage';
import { formatBytes, formatPercent } from '@/lib/utils/format';
import { cacheAnalyticsChartData, summarizeCacheAnalytics } from '@/lib/utils/cacheAnalytics';
import type { CacheAnalytics, UsageBucket, UsageSummary } from '@/types';

const bucket = ref<UsageBucket>(runtimeConfig.defaultUsageBucket);
const summary = ref<UsageSummary | null>(null);
const cacheAnalytics = ref<CacheAnalytics | null>(null);
const points = computed(() => summary.value?.points ?? []);
const cacheSummary = computed(() => summarizeCacheAnalytics(cacheAnalytics.value));
const requestTotal = computed(() => summary.value?.requests_count ?? summary.value?.total_requests ?? summary.value?.requests ?? 0);
const timeLabels = computed(() => points.value.map((point) => formatBucketTime(point.bucket_ts)));

const bandwidth = computed(() => ({
  tooltip: { trigger: 'axis', valueFormatter: (value: unknown) => formatBytes(Number(value)) },
  legend: { data: ['Bandwidth in', 'Bandwidth out'] },
  grid: { left: 72, right: 24, bottom: 56, top: 44 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value', axisLabel: { formatter: (value: number) => formatBytes(value) } },
  series: [
    { name: 'Bandwidth in', type: 'line', smooth: true, showSymbol: points.value.length < 40, data: points.value.map((p) => p.bytes_in) },
    { name: 'Bandwidth out', type: 'line', smooth: true, showSymbol: points.value.length < 40, data: points.value.map((p) => p.bytes_out) },
  ],
}));

const requests = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 64, right: 24, bottom: 56, top: 24 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value' },
  series: [{ name: 'Requests', type: 'bar', data: points.value.map((p) => p.requests_count) }],
}));

const cacheStates = computed(() => ({
  tooltip: {},
  legend: {},
  series: [
    {
      type: 'pie',
      radius: ['45%', '70%'],
      data: cacheAnalyticsChartData(cacheAnalytics.value),
    },
  ],
}));

async function load() {
  const [usage, cache] = await Promise.all([
    usageApi.summary({ bucket: bucket.value }).catch(() => null),
    cacheApi.analytics().catch(() => null),
  ]);
  summary.value = usage;
  cacheAnalytics.value = cache;
}

async function recalculate() {
  await usageApi.recalculate();
  await load();
}

function formatBucketTime(timestamp: number) {
  const date = new Date(timestamp * 1000);
  if (bucket.value === 'day') return date.toLocaleDateString();
  if (bucket.value === 'hour') return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit' });
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

onMounted(load);
</script>
