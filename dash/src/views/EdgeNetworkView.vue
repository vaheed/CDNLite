<template>
  <section class="space-y-6">
    <PageHeader eyebrow="Infrastructure" title="Edge Network" description="Monitor edge nodes, traffic pools, and generated platform DNS records.">
      <template #actions>
        <ReportExportButton title="Edge network" :data="{ edges: rows, pools, dns }" />
      </template>
    </PageHeader>

    <div v-if="warnings.length" role="alert" class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900 shadow-sm dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-100">
      <div class="flex gap-3">
        <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" />
        <div>
          <p class="font-semibold">Network configuration needs attention</p>
          <ul class="mt-1.5 space-y-1 text-amber-800 dark:text-amber-200">
            <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
          </ul>
        </div>
      </div>
    </div>

    <DataTable
      title="Nodes"
      subtitle="Registered nodes and their latest operational state."
      search-placeholder="Search nodes, regions, or IPs..."
      :rows="rows"
      :columns="columns"
    >
      <template #identity="{ row }">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="block max-w-48 truncate font-mono text-xs font-semibold text-slate-800 dark:text-slate-200" :title="String(row.edge_id)">{{ row.edge_id }}</span>
            <StatusBadge v-if="row.identity_status === 'warning'" status="warning" label="Suspicious" />
          </div>
          <span class="mt-1 block text-xs text-slate-400">Edge identity</span>
        </div>
      </template>
      <template #hostname="{ value }"><span class="block max-w-48 truncate font-medium" :title="String(value || 'Unknown')">{{ value || 'Unknown' }}</span></template>
      <template #public_ip="{ value }"><code class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700 dark:bg-white/[0.06] dark:text-slate-200">{{ value || 'Not reported' }}</code></template>
      <template #region="{ row }">
        <div class="whitespace-nowrap">
          <span class="font-medium">{{ row.region || 'Unassigned' }}</span>
          <span v-if="row.country" class="mt-1 block text-xs text-slate-400">{{ row.country }}</span>
        </div>
      </template>
      <template #modes="{ row }">
        <div class="flex flex-wrap gap-1.5">
          <StatusBadge v-if="row.geo_enabled" status="ok" label="Geo" />
          <StatusBadge v-if="row.anycast_enabled" status="info" label="Anycast" />
          <span v-if="!row.geo_enabled && !row.anycast_enabled" class="text-xs text-slate-400">Standard</span>
        </div>
      </template>
      <template #version="{ value }"><span class="whitespace-nowrap text-xs font-medium">{{ value || 'Unknown' }}</span></template>
      <template #heartbeat="{ value }"><span class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ value }}</span></template>
      <template #health="{ row }"><StatusBadge :status="String(row.health)" /></template>
    </DataTable>

    <div class="grid gap-6 lg:grid-cols-2 2xl:grid-cols-3">
      <article class="card flex min-h-80 flex-col p-5 sm:p-6">
        <div class="section-heading">
          <div>
            <h2>Pools</h2>
            <p>Configured geo and anycast membership.</p>
          </div>
          <div class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-700 dark:bg-blue-400/10 dark:text-blue-300"><Network class="h-5 w-5" /></div>
        </div>

        <div v-if="!pools.length" class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center dark:border-white/10 dark:bg-white/[0.02]">
          <div class="grid h-11 w-11 place-items-center rounded-xl bg-white text-slate-400 shadow-sm ring-1 ring-slate-200 dark:bg-white/[0.05] dark:ring-white/10"><Layers3 class="h-5 w-5" /></div>
          <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">No pools configured</h3>
          <p class="mt-1 max-w-xs text-xs leading-5 text-slate-500">Geo and anycast pool membership will appear here when configured.</p>
        </div>

        <div v-else class="space-y-3">
          <div v-for="pool in pools" :key="pool.id" class="rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 hover:shadow-sm dark:border-white/10 dark:hover:border-white/20">
            <div class="flex items-center justify-between gap-3">
              <strong class="text-sm text-slate-950 dark:text-white">{{ pool.name }}</strong>
              <StatusBadge :status="pool.mode === 'geo' ? 'ok' : 'info'" :label="pool.mode" />
            </div>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ pool.description || 'No description provided.' }}</p>
            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs dark:border-white/[0.06]">
              <span class="font-medium text-slate-600 dark:text-slate-300">{{ pool.members.length }} member{{ pool.members.length === 1 ? '' : 's' }}</span>
            </div>
            <div v-for="member in pool.members" :key="member.id" class="mt-2 flex items-center justify-between gap-3 text-xs">
              <span class="truncate font-mono text-slate-600 dark:text-slate-300">{{ member.edge_id }}</span>
              <span class="shrink-0 text-slate-400">Weight {{ member.weight }}</span>
            </div>
          </div>
        </div>
      </article>

      <article class="card min-h-80 p-5 sm:p-6">
        <div class="section-heading">
          <div>
            <h2>Platform DNS</h2>
            <p>{{ dns?.proxy_host || 'Shared proxy host not configured' }}</p>
          </div>
          <StatusBadge :status="dns?.synced_at ? 'ok' : 'warning'" :label="dns?.synced_at ? 'Synced' : 'Not synced'" />
        </div>

        <p v-if="dns?.synced_at" class="-mt-3 mb-4 text-xs text-slate-400">Last synchronized {{ formatDate(dns.synced_at) }}</p>
        <div v-if="staticAnycastSummary.length" class="mb-4 rounded-lg border border-cyan-200 bg-cyan-50/70 p-3 text-xs text-cyan-900 dark:border-cyan-400/20 dark:bg-cyan-400/10 dark:text-cyan-100">
          <div class="font-semibold">Static proxy anycast</div>
          <div class="mt-1 space-y-1">
            <code v-for="item in staticAnycastSummary" :key="item" class="block break-all">{{ item }}</code>
          </div>
        </div>
        <div v-if="!dns?.records.length" class="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center dark:border-white/10 dark:bg-white/[0.02]">
          <div class="grid h-11 w-11 place-items-center rounded-xl bg-white text-slate-400 shadow-sm ring-1 ring-slate-200 dark:bg-white/[0.05] dark:ring-white/10"><Database class="h-5 w-5" /></div>
          <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">No DNS records generated</h3>
          <p class="mt-1 max-w-xs text-xs leading-5 text-slate-500">Generated platform records will be listed after DNS synchronization.</p>
        </div>
        <div v-else class="space-y-2">
          <div v-for="record in visibleDnsRecords" :key="`${record.rrset_name}-${record.rrset_type}`" class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50/60 p-3 sm:grid-cols-[3rem_minmax(0,1fr)] dark:border-white/10 dark:bg-white/[0.025]">
            <span class="record-type self-start">{{ record.rrset_type }}</span>
            <div class="min-w-0 space-y-1">
              <code class="block break-all text-xs font-semibold text-slate-700 dark:text-slate-200">{{ record.rrset_name }}</code>
              <code class="block break-all text-xs leading-5 text-slate-500 dark:text-slate-400">{{ record.records.join(', ') }}</code>
            </div>
          </div>
          <button
            v-if="hasHiddenDnsRecords"
            type="button"
            class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950 focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:bg-white/[0.07] dark:hover:text-white"
            :aria-expanded="showAllDnsRecords"
            @click="showAllDnsRecords = !showAllDnsRecords"
          >
            <ChevronUp v-if="showAllDnsRecords" class="h-4 w-4" />
            <ChevronDown v-else class="h-4 w-4" />
            {{ showAllDnsRecords ? 'Show less' : `Show all ${dns.records.length} records` }}
          </button>
        </div>
      </article>

      <article class="card min-h-80 p-5 sm:p-6 lg:col-span-2 2xl:col-span-1">
        <div class="section-heading">
          <div>
            <h2>Network health</h2>
            <p>Current node availability at a glance.</p>
          </div>
          <StatusBadge :status="overallHealth" :label="overallHealthLabel" />
        </div>

        <div class="grid grid-cols-3 gap-2">
          <div v-for="item in healthSummary" :key="item.key" class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-white/10 dark:bg-white/[0.025]">
            <span :class="item.dot" class="mb-3 block h-2 w-2 rounded-full" />
            <strong class="block text-xl font-bold tracking-tight text-slate-950 dark:text-white">{{ item.value }}</strong>
            <span class="text-xs text-slate-500">{{ item.label }}</span>
          </div>
        </div>
        <div class="mx-auto mt-3 max-w-sm">
          <ChartCard bare compact :option="chart" />
        </div>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ChevronDown, ChevronUp, Database, Layers3, Network, TriangleAlert } from 'lucide-vue-next';
import DataTable from '@/components/ui/DataTable.vue';
import ChartCard from '@/components/ui/ChartCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
import { edgesApi } from '@/lib/api/edges';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import { useVisibilityPolling } from '@/lib/data/polling';
import { heartbeatStatus } from '@/lib/utils/diagnostics';
import { formatDate } from '@/lib/utils/format';
import type { EdgeDnsStatus, EdgeNode, EdgePool } from '@/types';

const edges = ref<EdgeNode[]>([]);
const pools = ref<EdgePool[]>([]);
const dns = ref<EdgeDnsStatus | null>(null);
const showAllDnsRecords = ref(false);
const dnsPreviewLimit = 3;
const hasHiddenDnsRecords = computed(() => (dns.value?.records.length ?? 0) > dnsPreviewLimit);
const visibleDnsRecords = computed(() => showAllDnsRecords.value ? dns.value?.records ?? [] : (dns.value?.records ?? []).slice(0, dnsPreviewLimit));
const staticAnycastSummary = computed(() => [
  dns.value?.static_anycast?.ipv4 ? `A ${dns.value.static_anycast.ipv4}` : '',
  dns.value?.static_anycast?.ipv6 ? `AAAA ${dns.value.static_anycast.ipv6}` : '',
].filter(Boolean));
const rows = computed(() => edges.value.map(edge => ({
  ...edge,
  public_ip: edge.public_ip || edge.public_ipv4 || edge.public_ipv6 || '',
  health: heartbeatStatus(edge),
  heartbeat: formatDate(edge.last_heartbeat_at ?? edge.last_heartbeat),
})));
const warnings = computed(() => [
  ...edges.value.filter(edge => !edge.public_ipv4 && !edge.public_ipv6 && !edge.public_ip).map(edge => `${edge.edge_id} has no public IP.`),
  ...edges.value.filter(edge => edge.identity_status === 'warning').map(edge => `${edge.edge_id} has a default or suspicious identity.`),
  ...(dns.value?.warnings ?? []).map(warning => `${warning.edge_id}: ${warning.error}`),
]);
const columns = [
  { key: 'identity', label: 'Identity', class: 'min-w-52' },
  { key: 'hostname', label: 'Hostname' },
  { key: 'public_ip', label: 'Public IP' },
  { key: 'region', label: 'Region' },
  { key: 'modes', label: 'Modes', sortable: false },
  { key: 'version', label: 'Version' },
  { key: 'heartbeat', label: 'Heartbeat' },
  { key: 'health', label: 'Health' },
];
const healthCounts = computed(() => ({
  ok: rows.value.filter(row => row.health === 'ok').length,
  warning: rows.value.filter(row => row.health === 'warning').length,
  critical: rows.value.filter(row => row.health === 'critical').length,
}));
const healthSummary = computed(() => [
  { key: 'ok', label: 'Online', value: healthCounts.value.ok, dot: 'bg-emerald-500' },
  { key: 'warning', label: 'Warning', value: healthCounts.value.warning, dot: 'bg-amber-500' },
  { key: 'critical', label: 'Critical', value: healthCounts.value.critical, dot: 'bg-red-500' },
]);
const overallHealth = computed(() => healthCounts.value.critical ? 'critical' : healthCounts.value.warning ? 'warning' : 'ok');
const overallHealthLabel = computed(() => overallHealth.value === 'ok' ? 'Operational' : overallHealth.value === 'warning' ? 'Degraded' : 'Action required');
const chart = computed(() => ({
  color: ['#10b981', '#f59e0b', '#ef4444'],
  tooltip: { trigger: 'item' },
  series: [{
    type: 'pie',
    radius: ['58%', '78%'],
    center: ['50%', '50%'],
    avoidLabelOverlap: true,
    label: { show: false },
    emphasis: { scaleSize: 4 },
    data: [
      { name: 'Online', value: healthCounts.value.ok },
      { name: 'Warning', value: healthCounts.value.warning },
      { name: 'Critical', value: healthCounts.value.critical },
    ],
  }],
}));

async function load() {
  [edges.value, pools.value, dns.value] = await Promise.all([
    edgesApi.list().catch(() => []),
    edgesApi.pools().catch(() => []),
    edgesApi.dns().catch(() => null),
  ]);
}

useInvalidationListener(() => [queryKeys.edgeNodes()], load);
useVisibilityPolling(load, 8000);
onMounted(load);
</script>
