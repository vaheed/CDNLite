<template>
  <section class="space-y-5">
    <div class="card flex flex-wrap items-center justify-between gap-4 p-4">
      <div>
        <p class="text-sm font-semibold">PowerDNS synchronization</p>
        <p class="text-xs text-slate-500">
          <span v-if="dnsStatus?.last_success_at">Last synced {{ new Date(dnsStatus.last_success_at * 1000).toLocaleString() }}.</span>
          <span v-else>Waiting for the first successful zone sync.</span>
          Proxied apex records publish as PowerDNS LUA; proxied subdomains publish as CNAME.
        </p>
        <p v-if="dnsStatus?.last_error" class="mt-1 text-xs text-rose-600">{{ dnsStatus.last_error }}</p>
        <p class="mt-1 text-xs text-slate-500">Records can be prepared before delegation. They publish automatically after nameserver verification and are withdrawn if delegation moves away.</p>
      </div>
      <StatusBadge :status="dnsStatus?.converged ? 'ok' : dnsStatus?.status === 'failed' ? 'critical' : 'warning'" :label="dnsStatus?.converged ? 'Synced' : String(dnsStatus?.status || 'Pending')" />
    </div>
    <div class="section-heading">
      <div><h2>DNS records</h2><p>Control public DNS, CDN proxying, and GeoDNS answers.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add record</button>
    </div>

    <form v-if="editing" class="panel-section space-y-6" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit DNS record' : 'Add DNS record' }}</h2><p>Choose DNS-only, proxied CDN, or raw GeoDNS mode.</p></div>
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
        <label class="xl:col-span-2"><span class="field-label">{{ form.proxied ? 'Default origin IP or hostname' : 'Default answer' }}</span><input v-model="form.content" class="input" placeholder="192.0.2.10" /><span class="field-description">{{ form.proxied ? 'Example: origin.example.com or 192.0.2.10. Avoid exposing this directly to visitors.' : 'Example: 192.0.2.10 for A, target.example.com for CNAME, or verification text for TXT.' }}</span></label>
        <label><span class="field-label">TTL</span><select v-model="form.ttl" class="input"><option :value="60">1 minute</option><option :value="300">5 minutes</option><option :value="3600">1 hour</option><option :value="86400">1 day</option></select><span class="field-description">Lower TTLs update faster but increase DNS query volume.</span></label>
        <label v-if="form.type === 'MX'"><span class="field-label">Priority</span><input v-model.number="form.priority" min="0" type="number" class="input" /><span class="field-description">Lower numbers are preferred first by mail senders.</span></label>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <label class="setting-row">
          <span><b>Proxy through CDNLite</b><small>Hide the origin and apply caching, WAF, and rate limits.</small></span>
          <input v-model="form.proxied" class="toggle" type="checkbox" @change="onProxyToggle" />
        </label>
        <label class="setting-row">
          <span><b>GeoDNS answers</b><small>Return country or continent-specific raw DNS answers.</small></span>
          <input v-model="form.geo_enabled" class="toggle" type="checkbox" :disabled="form.proxied || !geoDnsTypeSupported" @change="onGeoToggle" />
        </label>
      </div>
      <p v-if="!geoDnsTypeSupported" class="state-warning">GeoDNS answers are currently supported for A and AAAA records only.</p>

      <div v-if="form.geo_enabled" class="rounded-md border border-slate-200 dark:border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-white/10">
          <div><h3 class="text-sm font-semibold">GeoDNS answers</h3><p class="text-xs text-slate-500">Country rules win over continent rules; the default answer handles everything else.</p></div>
          <button type="button" class="button-secondary" @click="addGeoAnswer"><Plus class="h-4 w-4" /> Add rule</button>
        </div>
        <div v-if="geoAnswers.length" class="divide-y divide-slate-100 dark:divide-white/5">
          <div v-for="(answer, index) in geoAnswers" :key="index" class="grid gap-3 p-4 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1fr)_minmax(0,1.5fr)_auto] md:items-end">
            <label><span class="field-label">Scope</span><select v-model="answer.route_scope" class="input" @change="answer.country_code = ''; answer.continent_code = ''"><option value="country">Country</option><option value="continent">Continent</option></select></label>
            <label v-if="answer.route_scope === 'country'"><span class="field-label">Country</span><select v-model="answer.country_code" class="input"><option value="" disabled>Select country</option><option v-for="country in countryOptions" :key="country.code" :value="country.code">{{ country.name }}</option></select></label>
            <label v-else><span class="field-label">Continent</span><select v-model="answer.continent_code" class="input"><option value="" disabled>Select continent</option><option v-for="continent in continentOptions" :key="continent.code" :value="continent.code">{{ continent.name }}</option></select></label>
            <label><span class="field-label">Answer</span><input v-model="answer.answer_value" class="input" :placeholder="form.type === 'AAAA' ? '2001:db8::10' : '192.0.2.10'" /><span class="field-description">Must be a valid {{ form.type }} answer.</span></label>
            <button type="button" class="icon-button text-rose-600" title="Remove GeoDNS answer" @click="geoAnswers.splice(index, 1)"><Trash2 class="h-4 w-4" /></button>
          </div>
        </div>
        <p v-else class="p-6 text-center text-sm text-slate-500">No GeoDNS rules. All visitors receive the default answer.</p>
      </div>

      <div v-if="form.proxied" class="notice-info">
        <Cloud class="mt-0.5 h-5 w-5 shrink-0" />
        <p>{{ isApex(form.name) ? 'The apex is published as PowerDNS LUA from the shared edge pool' : 'This subdomain is published as a CNAME to the stable site target' }}. Origins remain private backend targets.</p>
      </div>
      <p v-if="error" class="state-error">{{ error }}</p>
      <div class="flex justify-end gap-2"><button type="button" class="button-secondary" @click="editing = false">Cancel</button><button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save record' }}</button></div>
    </form>

    <DataTable v-else-if="records.length" title="DNS records" subtitle="Authoritative records for this domain." :rows="records" :columns="columns">
      <template #type="{ value }"><span class="record-type">{{ value }}</span></template>
      <template #name="{ value }"><span class="font-mono font-medium">{{ value }}</span></template>
      <template #content="{ row }"><span class="font-mono text-xs">{{ row.proxied ? `${row.public_type} ${row.public_content}` : `${row.type} ${row.content}` }}</span><p v-if="row.readonly" class="mt-1 text-xs text-slate-500">Managed by platform nameserver settings.</p><p v-if="row.proxied" class="mt-1 text-xs text-slate-500">Published by CDNLite; private origin: {{ row.content }}</p><p v-if="Number(row.geo_routes_count) > 0" class="mt-1 text-xs text-cyan-700">GeoDNS: {{ row.geo_routes_count }} rules</p></template>
      <template #proxied="{ row }"><span :class="row.readonly ? 'status-neutral' : row.proxied ? 'status-proxied' : 'status-neutral'"><ShieldCheck v-if="row.readonly" class="h-3.5 w-3.5" /><Cloud v-else-if="row.proxied" class="h-3.5 w-3.5" /><CloudOff v-else class="h-3.5 w-3.5" />{{ row.readonly ? 'Managed' : row.proxied ? 'Proxied' : 'DNS only' }}</span></template>
      <template #status="{ row }"><StatusBadge :status="row.effective_status === 'active' ? 'healthy' : 'warning'" :label="row.effective_status === 'active' ? 'Active' : row.disabled_reason === 'nameservers_not_verified' ? 'Waiting for NS' : 'Disabled'" /></template>
      <template #actions="{ row }"><div class="flex gap-2"><span v-if="row.readonly" class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">Platform</span><template v-else><button class="button-secondary h-9 px-3 text-xs" @click="reconcile(row)">Retry sync</button><button class="icon-button" title="Edit record" @click="edit(row)"><Pencil class="h-4 w-4" /></button><ConfirmDangerButton class="h-9 px-3 text-xs" confirm-text="Delete this DNS record?" @confirm="remove(row)">Delete</ConfirmDangerButton></template></div></template>
    </DataTable>
    <EmptyState v-else title="No DNS records" message="Add your first DNS record to begin routing traffic." />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Cloud, CloudOff, Pencil, Plus, ShieldCheck, Trash2, X } from 'lucide-vue-next';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import { dnsApi } from '@/lib/api/dns';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { DnsRecord, DomainDnsStatus, GeoRouteScope } from '@/types';

type GeoAnswerForm = { route_scope: Exclude<GeoRouteScope, 'default'>; country_code: string; continent_code: string; answer_value: string };
const props = defineProps<{ domainId: string }>();
const records = ref<DnsRecord[]>([]);
const dnsStatus = ref<DomainDnsStatus | null>(null);
const geoAnswers = ref<GeoAnswerForm[]>([]);
const editing = ref(false);
const editingId = ref('');
const error = ref('');
const saving = ref(false);
const recordTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'];
const supportedCountryCodes = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ');
const countryNameFormatter = typeof Intl !== 'undefined' && 'DisplayNames' in Intl ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
const countryOptions = supportedCountryCodes.map((code) => ({ code, name: countryNameFormatter?.of(code) ?? code }));
const continentOptions = [
  { code: 'AF', name: 'Africa' }, { code: 'AN', name: 'Antarctica' }, { code: 'AS', name: 'Asia' },
  { code: 'EU', name: 'Europe' }, { code: 'NA', name: 'North America' }, { code: 'OC', name: 'Oceania' }, { code: 'SA', name: 'South America' },
];
const form = reactive({ type: 'A', name: '@', content: '', ttl: 300, priority: 10, proxied: false, geo_enabled: false });
const geoDnsTypeSupported = computed(() => ['A', 'AAAA'].includes(form.type));
const columns = [
  { key: 'type', label: 'Type' }, { key: 'name', label: 'Name' }, { key: 'content', label: 'Content / origin' },
  { key: 'proxied', label: 'Proxy status' }, { key: 'ttl', label: 'TTL' }, { key: 'status', label: 'Status' }, { key: 'actions', label: '' },
];

async function load() {
  const [result, status] = await Promise.all([dnsApi.list(props.domainId), dnsApi.status(props.domainId)]);
  dnsStatus.value = status;
  records.value = result;
}
function reset() {
  Object.assign(form, { type: 'A', name: '@', content: '', ttl: 300, priority: 10, proxied: false, geo_enabled: false });
  geoAnswers.value = [];
  error.value = '';
}
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
async function edit(value: Record<string, unknown>) {
  const row = value as unknown as DnsRecord;
  editingId.value = row.id;
  Object.assign(form, {
    type: row.type, name: row.name, content: row.origin_host || row.content, ttl: row.ttl || 300,
    priority: row.priority ?? 10, proxied: !!row.proxied, geo_enabled: Number(row.geo_routes_count ?? 0) > 0,
  });
  geoAnswers.value = [];
  if (Number(row.geo_routes_count ?? 0) > 0) {
    const routes = await dnsApi.geoRoutes(props.domainId, row.id);
    geoAnswers.value = routes.filter((route) => route.route_scope !== 'default').map((route) => ({
      route_scope: route.route_scope === 'continent' ? 'continent' : 'country',
      country_code: route.country_code ?? '',
      continent_code: route.continent_code ?? '',
      answer_value: route.answer_value ?? '',
    }));
  }
  error.value = '';
  editing.value = true;
}
function addGeoAnswer() { geoAnswers.value.push({ route_scope: 'country', country_code: '', continent_code: '', answer_value: '' }); }
function geoRoutePayload() {
  if (!form.geo_enabled) return [];
  return [
    { route_scope: 'default' as const, answer_type: form.type as 'A' | 'AAAA', answer_value: form.content.trim(), enabled: true },
    ...geoAnswers.value.map((answer) => ({
      route_scope: answer.route_scope,
      country_code: answer.route_scope === 'country' ? answer.country_code : null,
      continent_code: answer.route_scope === 'continent' ? answer.continent_code : null,
      answer_type: form.type as 'A' | 'AAAA',
      answer_value: answer.answer_value.trim(),
      enabled: true,
    })),
  ];
}
async function save() {
  error.value = '';
  if (!form.name.trim() || !form.content.trim()) { error.value = 'Name and content are required.'; return; }
  if (form.geo_enabled && !geoDnsTypeSupported.value) { error.value = 'GeoDNS answers are supported for A and AAAA records only.'; return; }
  if (form.geo_enabled && geoAnswers.value.some((item) => !(item.route_scope === 'country' ? item.country_code : item.continent_code) || !item.answer_value.trim())) { error.value = 'Select a location and enter an answer for every GeoDNS rule.'; return; }
  const routeKeys = geoAnswers.value.map((item) => `${item.route_scope}:${item.route_scope === 'country' ? item.country_code : item.continent_code}`);
  if (new Set(routeKeys).size !== routeKeys.length) { error.value = 'Each country or continent can have only one GeoDNS answer.'; return; }
  saving.value = true;
  try {
    const payload = {
      type: form.type, name: form.name.trim(), content: form.content.trim(), ttl: Number(form.ttl),
      priority: form.type === 'MX' ? Number(form.priority) : null, proxied: form.proxied,
      origin_host: form.proxied ? form.content.trim() : undefined, origin_tls_verify: 'ignore' as const,
      geo_routes: geoRoutePayload(), routing_policy: 'standard' as const,
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
function onProxyToggle() { if (form.proxied) form.geo_enabled = false; }
function onGeoToggle() { if (form.geo_enabled) form.proxied = false; }

watch(() => props.domainId, load);
watch(() => form.type, () => {
  if (!geoDnsTypeSupported.value) form.geo_enabled = false;
});
useInvalidationListener(() => [queryKeys.domainDns(props.domainId)], load);
onMounted(load);
</script>
