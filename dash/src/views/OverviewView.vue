<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-3xl font-black text-slate-950 dark:text-white">CDN Operations</h1>
        <p class="text-slate-600 dark:text-slate-400">Executive health, traffic, security, reliability, and change reporting from live control-plane data.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <select v-model="bucket" class="input w-32" aria-label="Report bucket">
          <option value="minute">Minute</option>
          <option value="hour">Hour</option>
          <option value="day">Day</option>
        </select>
        <button class="button-secondary" @click="load"><RefreshCw class="h-4 w-4" /> Refresh</button>
        <ReportExportButton title="CDN operations dashboard" :data="reportData" />
      </div>
    </div>

    <p v-if="errorMessage" role="alert" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ errorMessage }}</p>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="card in kpiCards" :key="card.label" class="metric-panel">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ card.label }}</p>
        <p class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{{ card.value }}</p>
        <p v-if="card.detail" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ card.detail }}</p>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.35fr_0.65fr]">
      <ChartCard title="Requests" :option="requestsChart" />
      <div class="card p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Needs Attention</h2>
        <p v-if="!summary?.warnings.length" class="mt-3 text-sm text-emerald-700 dark:text-emerald-300">No active report warnings for this range.</p>
        <ul v-else class="mt-3 space-y-3">
          <li v-for="warning in summary.warnings" :key="warning.message" class="flex items-center justify-between gap-3 rounded-md border border-slate-200 p-3 dark:border-white/10">
            <div>
              <StatusBadge :status="warning.severity" />
              <span class="ml-2 text-sm text-slate-700 dark:text-slate-200">{{ warning.message }}</span>
            </div>
            <RouterLink :to="warning.link" class="text-sm font-bold text-cyan-700 dark:text-cyan-300">Open</RouterLink>
          </li>
        </ul>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <ChartCard title="Bandwidth In / Out" :option="bandwidthChart" />
      <ChartCard title="Cache Status" :option="cacheChart" />
      <ChartCard title="Security Events" :option="securityChart" />
      <ChartCard title="Failed Jobs" :option="failedJobsChart" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <section class="card p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Top Domains</h2>
        <ul class="mt-3 divide-y divide-slate-200 dark:divide-white/10">
          <li v-for="domain in traffic?.top_domains ?? []" :key="domain.domain_id" class="flex items-center justify-between py-2 text-sm">
            <span class="truncate text-slate-700 dark:text-slate-200">{{ domain.domain }}</span>
            <span class="font-semibold text-slate-950 dark:text-white">{{ domain.requests }}</span>
          </li>
        </ul>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Edge Health</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">Online</dt><dd class="text-xl font-bold text-emerald-600">{{ edge?.counts.online ?? 0 }}</dd></div>
          <div><dt class="text-slate-500">Offline</dt><dd class="text-xl font-bold text-red-600">{{ edge?.counts.offline ?? 0 }}</dd></div>
          <div><dt class="text-slate-500">Config drift</dt><dd class="text-xl font-bold">{{ configDriftCount }}</dd></div>
          <div><dt class="text-slate-500">Config errors</dt><dd class="text-xl font-bold">{{ edge?.failed_config_pulls.length ?? 0 }}</dd></div>
        </dl>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Reliability</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">DNS converged</dt><dd class="text-xl font-bold">{{ reliability?.dns_zones.converged ?? 0 }}</dd></div>
          <div><dt class="text-slate-500">DNS pending</dt><dd class="text-xl font-bold">{{ reliability?.dns_zones.pending ?? 0 }}</dd></div>
          <div><dt class="text-slate-500">SSL expiring</dt><dd class="text-xl font-bold">{{ reliability?.certificates_expiring_soon.length ?? 0 }}</dd></div>
          <div><dt class="text-slate-500">Origin states</dt><dd class="text-xl font-bold">{{ reliability?.origin_health_counts.length ?? 0 }}</dd></div>
        </dl>
      </section>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <section class="card overflow-hidden p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Recent Problem Requests</h2>
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Time</th><th>Path</th><th>Status</th><th>Edge</th></tr></thead>
            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
              <tr v-for="request in traffic?.recent_problem_requests ?? []" :key="request.id">
                <td class="py-2 text-slate-500">{{ formatTime(request.ts) }}</td>
                <td class="max-w-56 truncate">{{ request.path || request.host || 'unknown' }}</td>
                <td><StatusBadge :status="request.status >= 500 ? 'critical' : 'warning'" /></td>
                <td>{{ request.edge_node_id }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="card overflow-hidden p-4">
        <h2 class="font-semibold text-slate-950 dark:text-white">Recent Jobs</h2>
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Created</th><th>Domain</th><th>Status</th><th>Message</th></tr></thead>
            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
              <tr v-for="job in operations?.recent_jobs ?? []" :key="job.id">
                <td class="py-2 text-slate-500">{{ formatTime(job.created_at) }}</td>
                <td>{{ job.domain_name || job.domain_id }}</td>
                <td><StatusBadge :status="job.status === 'failed' ? 'critical' : job.status" /></td>
                <td class="max-w-72 truncate">{{ job.message }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { RefreshCw } from 'lucide-vue-next';
import ChartCard from '@/components/ui/ChartCard.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { reportsApi } from '@/lib/api/reports';
import { runtimeConfig } from '@/lib/config/env';
import { formatBytes, formatPercent } from '@/lib/utils/format';
import type { ReportCache, ReportEdge, ReportOperations, ReportReliability, ReportSecurity, ReportSummary, ReportTraffic, UsageBucket } from '@/types';

const bucket = ref<UsageBucket>(runtimeConfig.defaultUsageBucket);
const summary = ref<ReportSummary | null>(null);
const traffic = ref<ReportTraffic | null>(null);
const cache = ref<ReportCache | null>(null);
const edge = ref<ReportEdge | null>(null);
const security = ref<ReportSecurity | null>(null);
const reliability = ref<ReportReliability | null>(null);
const operations = ref<ReportOperations | null>(null);
const errorMessage = ref('');

const reportQuery = computed(() => ({ bucket: bucket.value, compare: true, limit: 8 }));
const reportData = computed(() => ({ summary: summary.value, traffic: traffic.value, cache: cache.value, edge: edge.value, security: security.value, reliability: reliability.value, operations: operations.value }));
const configDriftCount = computed(() => edge.value?.config_version_drift.filter((item) => item.drift).length ?? 0);
const kpiCards = computed(() => {
  const kpis = summary.value?.kpis;
  return [
    { label: 'Requests', value: kpis?.total_requests ?? 0, detail: deltaText('total_requests') },
    { label: 'Bandwidth out', value: formatBytes(kpis?.bandwidth_out_bytes ?? 0), detail: deltaText('bandwidth_out_bytes') },
    { label: 'Cache hit ratio', value: formatPercent(kpis?.cache_hit_ratio ?? 0), detail: deltaText('cache_hit_ratio') },
    { label: 'Active domains', value: kpis?.active_domains ?? 0 },
    { label: 'Edges online', value: kpis?.online_edges ?? 0 },
    { label: 'Security events', value: kpis?.security_events ?? 0, detail: `${kpis?.waf_blocks ?? 0} WAF blocks` },
    { label: 'Origin errors', value: kpis?.origin_errors ?? 0 },
    { label: 'Pending DNS', value: kpis?.pending_dns_changes ?? 0 },
  ];
});

const timeLabels = computed(() => (traffic.value?.requests ?? []).map((point) => formatBucket(point.bucket_ts)));
const requestsChart = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 64, right: 24, bottom: 56, top: 24 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value' },
  series: [{ name: 'Requests', type: 'bar', data: (traffic.value?.requests ?? []).map((point) => point.value ?? 0) }],
}));
const bandwidthChart = computed(() => ({
  tooltip: { trigger: 'axis', valueFormatter: (value: unknown) => formatBytes(Number(value)) },
  legend: { data: ['In', 'Out'] },
  grid: { left: 72, right: 24, bottom: 56, top: 44 },
  xAxis: { type: 'category', data: timeLabels.value, axisLabel: { rotate: 30 } },
  yAxis: { type: 'value', axisLabel: { formatter: (value: number) => formatBytes(value) } },
  series: [
    { name: 'In', type: 'line', smooth: true, data: (traffic.value?.bandwidth.in ?? []).map((point) => point.value ?? 0) },
    { name: 'Out', type: 'line', smooth: true, data: (traffic.value?.bandwidth.out ?? []).map((point) => point.value ?? 0) },
  ],
}));
const cacheChart = computed(() => ({
  tooltip: {},
  legend: {},
  series: [{ type: 'pie', radius: ['45%', '70%'], data: (cache.value?.status_distribution ?? []).map((row) => ({ name: row.status, value: row.count })) }],
}));
const securityChart = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 56, right: 20, bottom: 56, top: 24 },
  xAxis: { type: 'category', data: (security.value?.events_over_time ?? []).map((point) => formatBucket(point.bucket_ts)), axisLabel: { rotate: 30 } },
  yAxis: { type: 'value' },
  series: [{ name: 'Events', type: 'bar', data: (security.value?.events_over_time ?? []).map((point) => point.count ?? 0) }],
}));
const failedJobsChart = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 56, right: 20, bottom: 56, top: 24 },
  xAxis: { type: 'category', data: (operations.value?.failed_jobs_over_time ?? []).map((point) => formatBucket(point.bucket_ts)), axisLabel: { rotate: 30 } },
  yAxis: { type: 'value' },
  series: [{ name: 'Failed jobs', type: 'bar', data: (operations.value?.failed_jobs_over_time ?? []).map((point) => point.count ?? 0) }],
}));

async function load() {
  errorMessage.value = '';
  try {
    [summary.value, traffic.value, cache.value, edge.value, security.value, reliability.value, operations.value] = await Promise.all([
      reportsApi.summary(reportQuery.value),
      reportsApi.traffic(reportQuery.value),
      reportsApi.cache(reportQuery.value),
      reportsApi.edge(reportQuery.value),
      reportsApi.security(reportQuery.value),
      reportsApi.reliability(reportQuery.value),
      reportsApi.operations(reportQuery.value),
    ]);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to load operations reports.';
  }
}

function formatBucket(timestamp: number) {
  const date = new Date(timestamp * 1000);
  if (bucket.value === 'day') return date.toLocaleDateString();
  if (bucket.value === 'hour') return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit' });
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatTime(timestamp?: number) {
  return timestamp ? new Date(timestamp * 1000).toLocaleString() : 'unknown';
}

function deltaText(key: string) {
  const delta = summary.value?.deltas?.[key];
  if (!delta || delta.percent === null) return '';
  const sign = delta.absolute > 0 ? '+' : '';
  return `${sign}${delta.percent}% vs previous range`;
}

onMounted(load);
</script>
