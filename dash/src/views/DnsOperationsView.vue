<template>
  <section class="space-y-6">
    <PageHeader eyebrow="Infrastructure" title="DNS Operations" description="Configure and inspect PowerDNS, DNSGeo, edge LUA readiness, desired records, and zone convergence.">
      <template #actions>
        <button class="button-secondary" :disabled="busy" @click="runDryRun">Dry run</button>
        <button class="button-primary" :disabled="busy" @click="forceSync">{{ busy ? 'Working...' : 'Force sync now' }}</button>
      </template>
    </PageHeader>

    <p v-if="message" class="notice-info">{{ message }}</p>

    <div class="grid gap-6 lg:grid-cols-2">
      <article class="card p-5">
        <div class="section-heading"><div><h2>PowerDNS setup</h2><p>Secrets are never returned by the API.</p></div><StatusBadge :status="operations?.setup.api.ok ? 'ok' : 'warning'" /></div>
        <dl class="space-y-3 text-sm">
          <div v-for="item in setupRows" :key="item.label" class="flex justify-between gap-4 border-b border-slate-100 pb-3 dark:border-white/10"><dt class="text-slate-500">{{ item.label }}</dt><dd class="break-all text-right font-medium">{{ item.value }}</dd></div>
        </dl>
        <RouterLink class="button-secondary mt-4" to="/settings">Edit PowerDNS settings</RouterLink>
      </article>
      <article class="card p-5">
        <div class="section-heading"><div><h2>DNSGeo readiness</h2><p>Required capabilities for shared edge routing.</p></div><StatusBadge :status="dnsGeoReady ? 'ok' : 'warning'" /></div>
        <div class="grid grid-cols-2 gap-3">
          <div v-for="item in dnsGeoRows" :key="item.label" class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
            <StatusBadge :status="item.ok ? 'ok' : 'warning'" :label="item.ok ? 'Ready' : 'Check'" />
            <p class="mt-2 text-sm font-medium">{{ item.label }}</p>
          </div>
        </div>
        <a class="button-secondary mt-4" :href="operations?.setup.poweradmin_url" target="_blank" rel="noreferrer">Open Poweradmin</a>
      </article>
    </div>

    <DataTable title="Zone synchronization" subtitle="Desired and applied PowerDNS state by authoritative zone." :rows="zones" :columns="zoneColumns">
      <template #status="{ row }"><StatusBadge :status="row.converged ? 'ok' : row.status === 'failed' ? 'critical' : 'warning'" :label="row.converged ? 'Synced' : String(row.status)" /></template>
      <template #last_success_at="{ value }">{{ formatDate(typeof value === 'number' || typeof value === 'string' ? value : null) }}</template>
      <template #last_error="{ value }"><span :class="value ? 'text-rose-600' : 'text-slate-400'">{{ value || 'None' }}</span></template>
    </DataTable>

    <DataTable title="Desired RRsets" subtitle="Safe preview of the records CDNLite owns and will reconcile." :rows="desired" :columns="recordColumns">
      <template #rrset_type="{ value }"><span class="record-type">{{ value }}</span></template>
      <template #records="{ value }"><code class="text-xs">{{ (value as string[]).join(', ') }}</code></template>
    </DataTable>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { dnsOperationsApi } from '@/lib/api/dnsOperations';
import { formatDate } from '@/lib/utils/format';
import type { DnsOperations, DnsZoneStatus, EdgeDnsRecord } from '@/types';

const operations = ref<DnsOperations | null>(null);
const zones = ref<DnsZoneStatus[]>([]);
const desired = ref<EdgeDnsRecord[]>([]);
const busy = ref(false);
const message = ref('');
const zoneColumns = [
  { key: 'zone_name', label: 'Zone' }, { key: 'status', label: 'Status' }, { key: 'pending_changes', label: 'Pending' },
  { key: 'desired_rrsets', label: 'RRsets' }, { key: 'last_success_at', label: 'Last success' }, { key: 'last_error', label: 'Last error' },
];
const recordColumns = [
  { key: 'zone_name', label: 'Zone' }, { key: 'rrset_name', label: 'Name' }, { key: 'rrset_type', label: 'Type' },
  { key: 'records', label: 'Records' }, { key: 'source', label: 'Owner' },
];
const setupRows = computed(() => {
  const setup = operations.value?.setup;
  return [
    { label: 'Enabled', value: setup?.enabled ? 'Yes' : 'No' },
    { label: 'API URL', value: setup?.api_url || 'Not configured' },
    { label: 'Server ID', value: setup?.server_id || 'Not configured' },
    { label: 'API key configured', value: setup?.api_key_configured ? 'Yes' : 'No' },
    { label: 'CDN zone', value: setup?.cdn_zone || 'Not configured' },
    { label: 'CDN proxy host', value: setup?.cdn_proxy_host || 'Not configured' },
    { label: 'Apex proxy mode', value: setup?.apex_proxy_mode || 'DIRECT' },
  ];
});
const dnsGeoRows = computed(() => {
  const status = operations.value?.dnsgeo;
  return [
    { label: 'PowerDNS auth', ok: !!status?.powerdns_auth }, { label: 'PostgreSQL', ok: !!status?.postgresql },
    { label: 'MMDB updater', ok: !!status?.mmdb }, { label: 'EDNS subnet', ok: !!status?.edns_subnet_processing },
    { label: 'Lua apex records', ok: !!status?.lua_records }, { label: 'No apex ALIAS', ok: !status?.alias_expansion },
    { label: 'Resolver', ok: !!status?.resolver_configured }, { label: 'API private', ok: !status?.api_publicly_exposed },
  ];
});
const dnsGeoReady = computed(() => dnsGeoRows.value.every(item => item.ok));

async function load() {
  [operations.value, zones.value, desired.value] = await Promise.all([
    dnsOperationsApi.status(), dnsOperationsApi.zones(), dnsOperationsApi.desired(),
  ]);
}
async function runDryRun() {
  busy.value = true;
  try {
    const result = await dnsOperationsApi.dryRun();
    message.value = `Dry run: ${result.planned_changes} desired RRsets across ${result.zones.length} zones.`;
  } finally { busy.value = false; }
}
async function forceSync() {
  busy.value = true;
  try {
    const result = await dnsOperationsApi.forceSync();
    message.value = result.ok ? `Desired state saved: ${result.planned_changes} pending RRsets across ${result.zones.length} zones.` : `Sync failed: ${result.error ?? 'unknown error'}.`;
    await load();
  } finally { busy.value = false; }
}
onMounted(load);
</script>
