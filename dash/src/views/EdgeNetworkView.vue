<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div><h1 class="text-3xl font-black">Edge Network</h1><p class="text-slate-500">Nodes, traffic pools, and generated platform DNS records.</p></div>
      <ReportExportButton title="Edge network" :data="{ edges: rows, pools, dns }"/>
    </div>

    <div v-if="warnings.length" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
      <p v-for="warning in warnings" :key="warning">{{ warning }}</p>
    </div>

    <DataTable title="Nodes" :rows="rows" :columns="columns">
      <template #identity="{row}"><div class="flex items-center gap-2"><span class="font-mono">{{row.edge_id}}</span><StatusBadge v-if="row.identity_status==='warning'" status="warning" label="Suspicious"/></div></template>
      <template #modes="{row}"><div class="flex gap-2"><StatusBadge v-if="row.geo_enabled" status="ok" label="Geo"/><StatusBadge v-if="row.anycast_enabled" status="info" label="Anycast"/></div></template>
      <template #health="{row}"><StatusBadge :status="String(row.health)"/></template>
    </DataTable>

    <div class="grid gap-6 xl:grid-cols-2">
      <div class="card p-5">
        <h2 class="text-xl font-bold">Pools</h2>
        <p class="mb-4 text-sm text-slate-500">Configured geo and anycast membership.</p>
        <div v-if="!pools.length" class="text-sm text-slate-500">No edge pools configured.</div>
        <div v-for="pool in pools" :key="pool.id" class="mb-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
          <div class="flex justify-between"><strong>{{ pool.name }}</strong><StatusBadge :status="pool.mode === 'geo' ? 'ok' : 'info'" :label="pool.mode"/></div>
          <p class="text-sm text-slate-500">{{ pool.description || 'No description' }}</p>
          <p class="mt-2 text-sm">{{ pool.members.length }} member{{ pool.members.length === 1 ? '' : 's' }}</p>
          <div v-for="member in pool.members" :key="member.id" class="mt-1 flex justify-between text-sm"><span class="font-mono">{{ member.edge_id }}</span><span>weight {{ member.weight }}</span></div>
        </div>
      </div>

      <div class="card p-5">
        <div class="flex items-start justify-between gap-3"><div><h2 class="text-xl font-bold">Platform DNS</h2><p class="text-sm text-slate-500">{{ dns?.base_domain || 'Not configured' }}</p></div><StatusBadge :status="dns?.synced_at ? 'ok' : 'warning'" :label="dns?.synced_at ? `Synced ${formatDate(dns.synced_at)}` : 'Not synced'"/></div>
        <div v-if="!dns?.records.length" class="mt-4 text-sm text-slate-500">No platform DNS records generated.</div>
        <div v-for="record in dns?.records ?? []" :key="`${record.fqdn}-${record.type}-${record.content}`" class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 rounded-lg bg-slate-100 p-3 text-sm dark:bg-slate-800">
          <strong>{{ record.type }}</strong><code class="break-all">{{ record.fqdn }} {{ record.content }}</code>
        </div>
      </div>
    </div>

    <ChartCard title="Online / Offline" :option="chart"/>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import ChartCard from '@/components/ui/ChartCard.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
import { edgesApi } from '@/lib/api/edges';
import { heartbeatStatus } from '@/lib/utils/diagnostics';
import { formatDate } from '@/lib/utils/format';
import type { EdgeDnsStatus, EdgeNode, EdgePool } from '@/types';

const edges = ref<EdgeNode[]>([]);
const pools = ref<EdgePool[]>([]);
const dns = ref<EdgeDnsStatus | null>(null);
const rows = computed(() => edges.value.map(edge => ({ ...edge, health: heartbeatStatus(edge), heartbeat: formatDate(edge.last_heartbeat_at ?? edge.last_heartbeat) })));
const warnings = computed(() => [
  ...edges.value.filter(edge => !edge.public_ipv4 && !edge.public_ipv6 && !edge.public_ip).map(edge => `${edge.edge_id} has no public IP.`),
  ...edges.value.filter(edge => edge.identity_status === 'warning').map(edge => `${edge.edge_id} has a default or suspicious identity.`),
  ...(dns.value?.warnings ?? []).map(warning => `${warning.edge_id}: ${warning.error}`),
]);
const columns = [
  { key: 'identity', label: 'Identity' }, { key: 'hostname', label: 'Hostname' }, { key: 'public_ip', label: 'Public IP' },
  { key: 'region', label: 'Region' }, { key: 'modes', label: 'Modes' }, { key: 'version', label: 'Version' },
  { key: 'heartbeat', label: 'Heartbeat' }, { key: 'health', label: 'Health' },
];
const chart = computed(() => ({ tooltip: {}, series: [{ type: 'pie', data: ['ok', 'warning', 'critical'].map(name => ({ name, value: rows.value.filter(row => row.health === name).length })) }] }));

onMounted(async () => {
  [edges.value, pools.value, dns.value] = await Promise.all([
    edgesApi.list().catch(() => []),
    edgesApi.pools().catch(() => []),
    edgesApi.dns().catch(() => null),
  ]);
});
</script>
