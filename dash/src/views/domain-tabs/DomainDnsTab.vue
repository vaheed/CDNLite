<template>
  <section class="space-y-5">
    <div class="card flex flex-wrap items-center justify-between gap-4 p-4">
      <div>
        <p class="text-sm font-semibold">PowerDNS synchronization</p>
        <p class="text-xs text-slate-500">
          <span v-if="dnsStatus?.last_success_at">Last synced {{ new Date(dnsStatus.last_success_at * 1000).toLocaleString() }}.</span>
          <span v-else>Waiting for the first successful zone sync.</span>
          Proxied apex records publish as ALIAS; proxied subdomains publish as CNAME.
        </p>
        <p v-if="dnsStatus?.last_error" class="mt-1 text-xs text-rose-600">{{ dnsStatus.last_error }}</p>
        <p class="mt-1 text-xs text-slate-500">Records can be prepared before delegation. They publish automatically after nameserver verification and are withdrawn if delegation moves away.</p>
      </div>
      <StatusBadge :status="dnsStatus?.converged ? 'ok' : dnsStatus?.status === 'failed' ? 'critical' : 'warning'" :label="dnsStatus?.converged ? 'Synced' : String(dnsStatus?.status || 'Pending')" />
    </div>
    <div class="section-heading">
      <div><h2>DNS records</h2><p>Control public DNS, CDN proxying, and country-specific origins.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add record</button>
    </div>

    <form v-if="editing" class="panel-section space-y-6" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit DNS record' : 'Add DNS record' }}</h2><p>Public DNS and origin delivery are configured together.</p></div>
        <button type="button" class="icon-button" title="Close" @click="editing = false"><X class="h-5 w-5" /></button>
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Common records</b><span>Use A for an IP address, CNAME for another hostname, TXT for verification, and MX for mail routing.</span></div>
        <div class="help-item"><b>Proxied records</b><span>When proxy is on, content is the private origin target and visitors connect through CDNLite.</span></div>
        <div class="help-item"><b>Safe TTL</b><span>Use 5 minutes while changing records, then increase to 1 hour or 1 day after things are stable.</span></div>
      </div>

      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <label><span class="field-label">Type</span><select v-model="form.type" class="input"><option v-for="type in recordTypes" :key="type">{{ type }}</option></select><span class="field-description">Choose the DNS record kind you want to publish.</span></label>
        <label><span class="field-label">Name</span><input v-model="form.name" class="input" placeholder="@ or www" /><span class="field-description">@ means the root domain. Use www, api, or mail for subdomains.</span></label>
        <label class="xl:col-span-2"><span class="field-label">{{ form.proxied ? 'Default origin IP or hostname' : 'Content' }}</span><input v-model="form.content" class="input" placeholder="192.0.2.10" /><span class="field-description">{{ form.proxied ? 'Example: origin.example.com or 192.0.2.10. Avoid exposing this directly to visitors.' : 'Example: 192.0.2.10 for A, target.example.com for CNAME, or verification text for TXT.' }}</span></label>
        <label v-if="form.proxied || form.geo_enabled"><span class="field-label">Default origin protocol</span><select v-model="form.origin_scheme" class="input"><option value="http">HTTP :80</option><option value="https">HTTPS :443</option></select><span class="field-description">Choose how the edge connects to the default origin.</span></label>
        <label><span class="field-label">TTL</span><select v-model="form.ttl" class="input"><option :value="60">1 minute</option><option :value="300">5 minutes</option><option :value="3600">1 hour</option><option :value="86400">1 day</option></select><span class="field-description">Lower TTLs update faster but increase DNS query volume.</span></label>
        <label v-if="form.type === 'MX'"><span class="field-label">Priority</span><input v-model.number="form.priority" min="0" type="number" class="input" /><span class="field-description">Lower numbers are preferred first by mail senders.</span></label>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <label class="setting-row">
          <span><b>Proxy through CDNLite</b><small>Hide the origin and apply caching, WAF, and rate limits.</small></span>
          <input v-model="form.proxied" class="toggle" type="checkbox" />
        </label>
        <label class="setting-row">
          <span><b>Geo origin routing</b><small>Keep country-specific origins configured independently from proxy status.</small></span>
          <input v-model="form.geo_enabled" class="toggle" type="checkbox" />
        </label>
      </div>

      <div v-if="form.geo_enabled" class="rounded-md border border-slate-200 dark:border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10">
          <div><h3 class="text-sm font-semibold">Country origins</h3><p class="text-xs text-slate-500">The default origin above handles countries without a rule.</p></div>
          <button type="button" class="button-secondary" @click="addGeoOrigin"><Plus class="h-4 w-4" /> Add country</button>
        </div>
        <div v-if="geoOrigins.length" class="divide-y divide-slate-100 dark:divide-white/5">
          <div v-for="(origin, index) in geoOrigins" :key="index" class="grid gap-3 p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_minmax(0,1fr)_auto_auto] md:items-end">
            <label><span class="field-label">Visitor country</span><select v-model="origin.country_code" class="input"><option value="" disabled>Select country</option><option v-for="country in countryOptions" :key="country.code" :value="country.code">{{ country.name }}</option></select><span class="field-description">Traffic from this country uses the origin below.</span></label>
            <label><span class="field-label">Origin IP or hostname</span><input v-model="origin.host" class="input" placeholder="origin-us.example.com" /><span class="field-description">Use a backend close to that visitor region.</span></label>
            <label><span class="field-label">Protocol</span><select v-model="origin.scheme" class="input"><option value="http">HTTP :80</option><option value="https">HTTPS :443</option></select><span class="field-description">Country override connection.</span></label>
            <label class="flex min-h-10 items-center gap-2 text-sm"><input v-model="origin.verify_tls" type="checkbox" /> Verify TLS</label>
            <button type="button" class="icon-button text-rose-600" title="Remove country origin" @click="geoOrigins.splice(index, 1)"><Trash2 class="h-4 w-4" /></button>
          </div>
        </div>
        <p v-else class="p-6 text-center text-sm text-slate-500">No country overrides. All traffic uses the default origin.</p>
      </div>

      <div v-if="form.proxied" class="notice-info">
        <Cloud class="mt-0.5 h-5 w-5 shrink-0" />
        <p>{{ isApex(form.name) ? 'The apex is published as a PowerDNS ALIAS' : 'This subdomain is published as a CNAME' }} to the stable site target. Origins remain private backend targets.</p>
      </div>
      <p v-if="error" class="state-error">{{ error }}</p>
      <div class="flex justify-end gap-2"><button type="button" class="button-secondary" @click="editing = false">Cancel</button><button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save record' }}</button></div>
    </form>

    <DataTable v-else-if="records.length" title="DNS records" subtitle="Authoritative records for this domain." :rows="records" :columns="columns">
      <template #type="{ value }"><span class="record-type">{{ value }}</span></template>
      <template #name="{ value }"><span class="font-mono font-medium">{{ value }}</span></template>
      <template #content="{ row }"><span class="font-mono text-xs">{{ row.proxied ? `${row.public_type} ${row.public_content}` : `${row.type} ${row.content}` }}</span><p v-if="row.proxied" class="mt-1 text-xs text-slate-500">Published by CDNLite; private origin: {{ row.content }}</p><p v-if="Number(row.geo_origins_count) > 0" class="mt-1 text-xs text-cyan-700">{{ row.geo_origins_count }} country origins</p></template>
      <template #proxied="{ row }"><span :class="row.proxied ? 'status-proxied' : 'status-neutral'"><Cloud v-if="row.proxied" class="h-3.5 w-3.5" /><CloudOff v-else class="h-3.5 w-3.5" />{{ row.proxied ? 'Proxied' : 'DNS only' }}</span></template>
      <template #status="{ row }"><StatusBadge :status="row.effective_status === 'active' ? 'healthy' : 'warning'" :label="row.effective_status === 'active' ? 'Active' : row.disabled_reason === 'nameservers_not_verified' ? 'Waiting for NS' : 'Disabled'" /></template>
      <template #actions="{ row }"><div class="flex gap-2"><button class="button-secondary h-9 px-3 text-xs" @click="reconcile(row)">Retry sync</button><button class="icon-button" title="Edit record" @click="edit(row)"><Pencil class="h-4 w-4" /></button><ConfirmDangerButton class="h-9 px-3 text-xs" confirm-text="Delete this DNS record?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
    </DataTable>
    <EmptyState v-else title="No DNS records" message="Add your first DNS record to begin routing traffic." />
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { Cloud, CloudOff, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import { dnsApi } from '@/lib/api/dns';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { DnsRecord, DomainDnsStatus } from '@/types';

type OriginScheme = 'http' | 'https';
type GeoOriginForm = { country_code: string; host: string; scheme: OriginScheme; verify_tls: boolean };
const props = defineProps<{ domainId: string }>();
const records = ref<Array<DnsRecord & { geo_origins_count: number }>>([]);
const dnsStatus = ref<DomainDnsStatus | null>(null);
const geoOrigins = ref<GeoOriginForm[]>([]);
const editing = ref(false);
const editingId = ref('');
const error = ref('');
const saving = ref(false);
const recordTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'];
const countryOptions = [
  { code: 'IR', name: 'Iran' }, { code: 'US', name: 'United States' }, { code: 'CA', name: 'Canada' },
  { code: 'DE', name: 'Germany' }, { code: 'FR', name: 'France' }, { code: 'GB', name: 'United Kingdom' },
  { code: 'NL', name: 'Netherlands' }, { code: 'TR', name: 'Turkey' }, { code: 'AE', name: 'United Arab Emirates' },
  { code: 'IN', name: 'India' }, { code: 'SG', name: 'Singapore' }, { code: 'JP', name: 'Japan' },
  { code: 'AU', name: 'Australia' }, { code: 'BR', name: 'Brazil' }, { code: 'ZA', name: 'South Africa' },
];
const form = reactive({ type: 'A', name: '@', content: '', ttl: 300, priority: 10, proxied: false, geo_enabled: false, origin_scheme: 'http' as OriginScheme });
const columns = [
  { key: 'type', label: 'Type' }, { key: 'name', label: 'Name' }, { key: 'content', label: 'Content / origin' },
  { key: 'proxied', label: 'Proxy status' }, { key: 'ttl', label: 'TTL' }, { key: 'status', label: 'Status' }, { key: 'actions', label: '' },
];

async function load() {
  const [result, status] = await Promise.all([dnsApi.list(props.domainId), dnsApi.status(props.domainId)]);
  dnsStatus.value = status;
  records.value = result.map((record) => ({ ...record, geo_origins_count: Object.keys(record.geo_origins ?? {}).filter((key) => key !== 'DEFAULT').length }));
}
function reset() {
  Object.assign(form, { type: 'A', name: '@', content: '', ttl: 300, priority: 10, proxied: false, geo_enabled: false, origin_scheme: 'http' });
  geoOrigins.value = [];
  error.value = '';
}
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function edit(value: Record<string, unknown>) {
  const row = value as unknown as DnsRecord;
  editingId.value = row.id;
  Object.assign(form, {
    type: row.type, name: row.name, content: row.origin_host || row.content, ttl: row.ttl || 300,
    priority: row.priority ?? 10, proxied: !!row.proxied, geo_enabled: Object.keys(row.geo_origins ?? {}).some((key) => key !== 'DEFAULT'),
    origin_scheme: (row.geo_origins?.DEFAULT?.scheme ?? row.origin_scheme ?? 'http') === 'https' ? 'https' : 'http',
  });
  geoOrigins.value = Object.entries(row.geo_origins ?? {}).filter(([country]) => country !== 'DEFAULT').map(([country, origin]) => ({
    country_code: country, host: origin.host, scheme: origin.scheme === 'https' ? 'https' : 'http', verify_tls: origin.tls_verify !== 'ignore',
  }));
  error.value = '';
  editing.value = true;
}
function addGeoOrigin() { geoOrigins.value.push({ country_code: '', host: '', scheme: form.origin_scheme, verify_tls: false }); }
function geoOriginPayload() {
  const origins: Record<string, { host: string; scheme: OriginScheme; port: 80 | 443; tls_verify: 'verify' | 'ignore' }> = {};
  if (form.content.trim()) origins.DEFAULT = { host: form.content.trim(), ...originProtocolPayload(form.origin_scheme), tls_verify: 'ignore' };
  if (form.geo_enabled) {
    for (const origin of geoOrigins.value) origins[origin.country_code] = { host: origin.host.trim(), ...originProtocolPayload(origin.scheme), tls_verify: origin.verify_tls ? 'verify' : 'ignore' };
  }
  return origins;
}
function originProtocolPayload(scheme: OriginScheme) { return { scheme, port: scheme === 'https' ? 443 as const : 80 as const }; }
async function save() {
  error.value = '';
  if (!form.name.trim() || !form.content.trim()) { error.value = 'Name and content are required.'; return; }
  if (form.geo_enabled && geoOrigins.value.some((item) => !item.country_code || !item.host.trim())) { error.value = 'Select a country and enter an origin for every Geo-DNS rule.'; return; }
  if (new Set(geoOrigins.value.map((item) => item.country_code)).size !== geoOrigins.value.length) { error.value = 'Each country can have only one origin.'; return; }
  saving.value = true;
  try {
    const payload = {
      type: form.type, name: form.name.trim(), content: form.content.trim(), ttl: Number(form.ttl),
      priority: form.type === 'MX' ? Number(form.priority) : null, proxied: form.proxied,
      origin_host: form.content.trim(), origin_scheme: form.origin_scheme, origin_tls_verify: 'ignore' as const,
      geo_origins: geoOriginPayload(), routing_policy: 'standard' as const,
    };
    if (editingId.value) await dnsApi.update(props.domainId, editingId.value, payload);
    else await dnsApi.create(props.domainId, payload);
    editing.value = false;
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to save DNS record.';
  } finally { saving.value = false; }
}
async function remove(value: Record<string, unknown>) {
  error.value = '';
  try {
    await dnsApi.remove(props.domainId, String(value.id));
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to delete DNS record.';
  }
}
async function reconcile(value: Record<string, unknown>) {
  error.value = '';
  try {
    await dnsApi.reconcileRecord(props.domainId, String(value.id));
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to retry DNS sync.';
  }
}
function isApex(name: string) { return ['', '@'].includes(name.trim().replace(/\.$/, '').toLowerCase()); }

watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainDns(props.domainId)], load);
onMounted(load);
</script>
