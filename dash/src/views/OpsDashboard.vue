<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black tracking-tight text-white">Ops & Troubleshooting</h1>
      <p class="text-slate-400">High-signal runtime diagnostics for fast triage.</p>
    </div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
      <div v-for="card in cards" :key="card.label" class="card p-4">
        <p class="text-sm text-slate-400">{{ card.label }}</p>
        <p class="mt-2 text-2xl font-black text-white">{{ card.value }}</p>
      </div>
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
      <ChartCard title="Requests over time" subtitle="Uses summary points when provided by the API." :option="requestChart" />
      <ChartCard title="Cache states" subtitle="Hit, miss, bypass, and stale distribution." :option="cachePie" />
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
      <DataTable title="Edge Health" :rows="edgeRows" :columns="edgeColumns">
        <template #health_status="{ row }"><StatusBadge :status="String(row.health_status)" /></template>
      </DataTable>
      <DataTable title="Recent Security Events" :rows="securityRows" :columns="securityColumns" />
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
      <DataTable title="SSL Risk View" :rows="sslRows" :columns="sslColumns"><template #risk="{ row }"><StatusBadge :status="String(row.risk)" /></template></DataTable>
      <DataTable title="Purge Timeline" :rows="purgeRows" :columns="purgeColumns" />
    </div>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ChartCard from '@/components/ui/ChartCard.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { sitesApi } from '@/lib/api/sites';
import { edgesApi } from '@/lib/api/edges';
import { usageApi } from '@/lib/api/usage';
import { cacheApi } from '@/lib/api/cache';
import { purgeApi } from '@/lib/api/purge';
import { sslApi } from '@/lib/api/ssl';
import { loadSecurityEventsForSites } from '@/lib/api/securityEvents';
import { buildOpsDiagnostic, heartbeatStatus, sslRisk } from '@/lib/utils/diagnostics';
import { formatBytes, formatDate, formatPercent } from '@/lib/utils/format';
import type { CacheAnalytics, EdgeNode, PurgeRequest, SecurityEvent, Site, SslCertificate, UsageSummary } from '@/types';
const sites = ref<Site[]>([]); const edges = ref<EdgeNode[]>([]); const usage = ref<UsageSummary | null>(null); const security = ref<SecurityEvent[]>([]); const certs = ref<SslCertificate[]>([]); const purges = ref<PurgeRequest[]>([]); const cache = ref<CacheAnalytics[]>([]);
const diagnostic = computed(() => buildOpsDiagnostic({ sites: sites.value, edges: edges.value, usage: usage.value, securityEvents: security.value, sslCertificates: certs.value, purges: purges.value, cacheAnalytics: cache.value }));
const cards = computed(() => [
  { label: 'Sites', value: diagnostic.value.sites }, { label: 'Edges', value: diagnostic.value.edges }, { label: 'Security Events (recent)', value: diagnostic.value.recentSecurityEvents }, { label: 'SSL Risks', value: diagnostic.value.sslRisks }, { label: 'Total Requests', value: diagnostic.value.totalRequests },
  { label: 'Bytes In', value: formatBytes(diagnostic.value.bytesIn) }, { label: 'Bytes Out', value: formatBytes(diagnostic.value.bytesOut) }, { label: 'Cache Hit Ratio', value: formatPercent(diagnostic.value.cacheHitRatio) }, { label: 'Offline Edges', value: diagnostic.value.offlineEdges }, { label: 'Purges Pending/Recent', value: diagnostic.value.recentPurges },
]);
const edgeRows = computed(() => edges.value.map((edge) => ({ ...edge, health_status: heartbeatStatus(edge), heartbeat: formatDate(edge.last_heartbeat_at ?? edge.last_heartbeat) })));
const securityRows = computed(() => security.value.map((event) => ({ site: event.site_name ?? event.site_id, type: event.type, decision: event.decision ?? event.action, time: formatDate(event.timestamp ?? event.created_at) })));
const sslRows = computed(() => certs.value.map((cert) => ({ site: cert.id, hostname: cert.hostname, status: cert.status ?? 'unknown', days_left: cert.days_left ?? '', risk: sslRisk(cert) })));
const purgeRows = computed(() => purges.value.map((purge) => ({ site: purge.site_id, type: purge.type, status: purge.status, time: formatDate(purge.created_at) })));
const edgeColumns = [{ key: 'edge_id', label: 'Edge' }, { key: 'region', label: 'Region' }, { key: 'public_ip', label: 'IP' }, { key: 'heartbeat', label: 'Heartbeat' }, { key: 'health_status', label: 'Status' }];
const securityColumns = [{ key: 'site', label: 'Site' }, { key: 'type', label: 'Type' }, { key: 'decision', label: 'Decision' }, { key: 'time', label: 'Time' }];
const sslColumns = [{ key: 'site', label: 'Site' }, { key: 'hostname', label: 'Hostname' }, { key: 'status', label: 'Status' }, { key: 'days_left', label: 'Days Left' }, { key: 'risk', label: 'Risk' }];
const purgeColumns = [{ key: 'site', label: 'Site' }, { key: 'type', label: 'Type' }, { key: 'status', label: 'Status' }, { key: 'time', label: 'Time' }];
const requestChart = computed(() => ({ tooltip: {}, grid: { left: 40, right: 20, top: 20, bottom: 40 }, xAxis: { type: 'category', data: (usage.value?.points ?? []).map((p) => String(p.bucket ?? p.time ?? 'now')) }, yAxis: { type: 'value' }, series: [{ type: 'line', smooth: true, data: (usage.value?.points ?? []).map((p) => Number(p.requests ?? 0)) }] }));
const cachePie = computed(() => { const totals = cache.value.reduce<{ hit: number; miss: number; bypass: number; stale: number }>((acc, item) => ({ hit: acc.hit + (item.hit ?? 0), miss: acc.miss + (item.miss ?? 0), bypass: acc.bypass + (item.bypass ?? 0), stale: acc.stale + (item.stale ?? 0) }), { hit: 0, miss: 0, bypass: 0, stale: 0 }); return { tooltip: {}, legend: {}, series: [{ type: 'pie', radius: ['45%', '70%'], data: Object.entries(totals).map(([name, value]) => ({ name, value })) }] }; });
async function load() {
  sites.value = await sitesApi.list().catch(() => []);
  const [edgeList, usageSummary, eventList] = await Promise.all([edgesApi.list().catch(() => []), usageApi.summary().catch(() => null), loadSecurityEventsForSites(sites.value).catch(() => [])]);
  edges.value = edgeList; usage.value = usageSummary; security.value = eventList;
  const perSite = await Promise.allSettled(sites.value.map(async (site) => ({ certs: await sslApi.certificates(site.id).catch(() => []), purges: await purgeApi.list(site.id).catch(() => []), cache: await cacheApi.analytics(site.id).catch(() => null) })));
  certs.value = perSite.flatMap((r) => r.status === 'fulfilled' ? r.value.certs : []);
  purges.value = perSite.flatMap((r) => r.status === 'fulfilled' ? r.value.purges : []);
  cache.value = perSite.flatMap((r) => r.status === 'fulfilled' && r.value.cache ? [r.value.cache] : []);
}
onMounted(load);
</script>
