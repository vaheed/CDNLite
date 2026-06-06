<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">{{ feature.title }}</h1>
      <p class="text-slate-600 dark:text-slate-400">{{ feature.subtitle }}</p>
    </div>
    <div class="card p-5">
      <div class="grid gap-4 md:grid-cols-[280px_1fr]">
        <label class="space-y-2">
          <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Domain</span>
          <select v-model="domainId" class="input"><option value="">Select a domain…</option><option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }} — {{ domain.domain }}</option></select>
          <p class="text-xs text-slate-500 dark:text-slate-400">All APIs in this section are domain-scoped. Select the domain first.</p>
        </label>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-slate-950/50">
          <p class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">Supported endpoints</p>
          <ul class="list-disc space-y-1 pl-5 text-sm text-slate-500 dark:text-slate-400"><li v-for="endpoint in feature.endpointSummary" :key="endpoint"><code>{{ endpoint }}</code></li></ul>
        </div>
      </div>
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2">
      <button v-if="feature.key === 'ssl'" type="button" class="button-primary" :disabled="!domainId || saving || !selectedDomain?.proxy_enabled" @click="issueAcme">Issue ACME SSL</button>
      <button v-if="feature.key === 'ssl'" type="button" class="button-secondary" :disabled="!domainId || saving || !selectedDomain?.proxy_enabled" @click="requestSsl">Create SSL request</button>
      <button v-if="feature.key === 'ssl'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="checkSsl">Run SSL check</button>
      <button type="button" class="button-primary" :disabled="!domainId || saving" @click="startCreate">{{ createLabel }}</button>
    </div>
    <div v-if="feature.key === 'dns' && domainId" class="card space-y-4 p-5">
      <div>
        <h2 class="text-lg font-bold text-slate-950 dark:text-white">Routing mode</h2>
        <p class="text-sm text-slate-500">Geo publishes PowerDNS LUA health routing. Anycast publishes configured A/AAAA or CNAME targets. DNS only preserves origin records.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-3">
        <label class="space-y-2"><span class="text-sm font-semibold">Mode</span><select v-model="routingForm.routing_mode" class="input"><option value="geo">Geo</option><option value="anycast">Anycast</option><option value="dns_only">DNS only</option></select></label>
        <label class="space-y-2"><span class="text-sm font-semibold">Anycast IPv4</span><input v-model="routingForm.anycast_ipv4" class="input" placeholder="192.0.2.10" /></label>
        <label class="space-y-2"><span class="text-sm font-semibold">Anycast CNAME</span><input v-model="routingForm.anycast_cname" class="input" placeholder="edge.example.net" /></label>
      </div>
      <button type="button" class="button-primary" :disabled="saving" @click="saveRouting">Save routing mode</button>
    </div>
    <form v-if="showForm" class="card grid gap-4 p-4 sm:p-5 xl:grid-cols-2" @submit.prevent="submit">
      <div v-if="formError" role="alert" class="xl:col-span-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ formError }}</div>
      <template v-for="field in feature.fields" :key="field.name">
        <label v-if="field.type === 'checkbox'" class="space-y-2 rounded-lg border border-slate-200 p-4 dark:border-white/10">
          <div class="flex items-center gap-3"><input v-model="form[field.name]" type="checkbox" class="h-4 w-4" /><span class="font-semibold text-slate-950 dark:text-white">{{ field.label }}</span></div>
          <div class="text-xs text-slate-500 dark:text-slate-400"><p><b>What this is:</b> {{ field.what }}</p><p><b>How this works:</b> {{ field.works }}</p><p><b>Example:</b> <code>{{ field.example }}</code></p></div>
        </label>
        <TextareaInput v-else-if="field.type === 'textarea'" v-model="form[field.name]" :help="toHelp(field)" />
        <label v-else-if="field.type === 'select'" class="space-y-2">
          <span class="flex items-center gap-1 text-sm font-semibold text-slate-800 dark:text-slate-100">
            {{ field.label }}
            <span v-if="field.required" class="text-red-600 dark:text-red-400">*</span>
          </span>
          <select v-model="form[field.name]" class="input">
            <option v-for="option in field.options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
          <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">{{ field.what }} Example: {{ field.example }}</p>
        </label>
        <TextInput v-else v-model="form[field.name]" :type="field.type === 'number' ? 'number' : 'text'" :help="toHelp(field)" />
      </template>
      <div class="xl:col-span-2 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-amber-200" v-if="feature.key === 'purge'">Purge type “everything” can invalidate all cached content for the selected domain.</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="editingId" type="button" class="button-secondary" @click="cancelEdit">Cancel edit</button>
          <button v-if="feature.key === 'redirects'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="testRedirect">Test redirect</button>
          <button v-if="feature.key === 'redirects'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="exportRedirects">Export redirects</button>
          <button v-if="feature.key === 'redirects'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="importRedirects">Import current rule</button>
          <button v-if="feature.key === 'page-rules'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="testPageRule">Test rule</button>
          <button v-if="feature.key === 'cache'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="saveCacheRule">Save cache rule</button>
          <button v-if="feature.key === 'security'" type="button" class="button-secondary" :disabled="!domainId || saving" @click="loadSecurityEvents">Load events</button>
          <button class="button-primary" :disabled="!domainId || saving">{{ editingId ? 'Save changes' : submitLabel }}</button>
        </div>
      </div>
    </form>
    <div class="grid gap-4 xl:grid-cols-2">
      <DataTable :title="`${feature.title} Records`" :rows="rows" :columns="columns">
        <template #enabled="{ row }">
          <button v-if="feature.key === 'redirects'" class="button-secondary px-2 py-1 text-xs" @click="toggleRedirect(row)">{{ row.enabled ? 'Disable' : 'Enable' }}</button>
          <button v-else-if="feature.key === 'rate-limit'" class="button-secondary px-2 py-1 text-xs" @click="toggleRateLimit(row)">{{ row.enabled ? 'Disable' : 'Enable' }}</button>
          <span v-else>{{ String(row.enabled ?? '') }}</span>
        </template>
        <template #actions="{ row }">
          <div class="flex flex-wrap gap-2">
            <button v-if="feature.key === 'dns'" class="button-secondary px-2 py-1 text-xs" @click="toggleDnsProxy(row)">{{ row.proxied ? 'DNS only' : 'Proxy' }}</button>
            <button v-if="feature.key === 'dns'" class="button-secondary px-2 py-1 text-xs" @click="previewDns(row)">Preview</button>
            <button v-if="canEdit(row)" class="button-secondary px-2 py-1 text-xs" @click="editRow(row)">Edit</button>
            <button v-if="feature.key === 'purge'" class="button-secondary px-2 py-1 text-xs" @click="getPurgeRequest(row)">Details</button>
            <ConfirmDangerButton v-if="canDelete(row)" class="px-2 py-1 text-xs" :confirm-text="`Delete this ${feature.title.toLowerCase()} record?`" @confirm="deleteRow(row)">Delete</ConfirmDangerButton>
          </div>
        </template>
      </DataTable>
      <div class="card p-5">
        <h3 class="font-semibold text-slate-950 dark:text-white">Raw JSON / Test Result</h3>
        <pre class="mt-4 max-h-[520px] overflow-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-300">{{ JSON.stringify(lastResult, null, 2) }}</pre>
      </div>
    </div>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import TextInput from '@/components/forms/TextInput.vue';
import TextareaInput from '@/components/forms/TextareaInput.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { cacheApi } from '@/lib/api/cache';
import { dnsApi } from '@/lib/api/dns';
import { pageRulesApi } from '@/lib/api/pageRules';
import { purgeApi } from '@/lib/api/purge';
import { rateLimitApi } from '@/lib/api/rateLimit';
import { redirectsApi } from '@/lib/api/redirects';
import { domainsApi } from '@/lib/api/domains';
import { sslApi } from '@/lib/api/ssl';
import { wafApi } from '@/lib/api/waf';
import type { CacheRule, DnsRecord, DomainRoutingSettings, PageRule, RedirectRule, Domain, WafRule } from '@/types';
import type { FeatureKey, FeaturePage } from './featurePages';
const props = defineProps<{ feature: FeaturePage }>();
const domains = ref<Domain[]>([]); const domainId = ref(''); const rows = ref<Record<string, unknown>[]>([]); const saving = ref(false); const lastResult = ref<unknown>(null); const formError = ref(''); const editingId = ref(''); const showForm = ref(false); const form = reactive<Record<string, string | number | boolean>>({});
const routingForm = reactive<Partial<DomainRoutingSettings>>({ routing_mode: 'geo', geo_health_port: 443, geo_selector: 'pickclosest', anycast_ipv4: '', anycast_ipv6: '', anycast_cname: '' });
const selectedDomain = computed(() => domains.value.find((domain) => domain.id === domainId.value));
const columns = computed(() => {
  if (props.feature.columns) return props.feature.columns;
  return Object.keys(rows.value[0] ?? { id: '', name: '', status: '' }).slice(0, 8).map((key) => ({ key, label: key.replaceAll('_', ' ') }));
});
const submitLabel = computed(() => props.feature.key === 'purge' ? 'Purge cache' : props.feature.key === 'cache' ? 'Save cache settings' : props.feature.key === 'ssl' ? 'Import certificate' : `Save ${props.feature.title}`);
const createLabel = computed(() => props.feature.key === 'ssl' ? 'Import certificate' : props.feature.key === 'purge' ? 'New purge' : props.feature.key === 'cache' ? 'Edit cache settings' : `Add ${props.feature.title}`);
function toHelp(field: FeaturePage['fields'][number]) { return { label: field.label, what: field.what, works: field.works, example: field.example, required: field.required }; }
function resetForm() { props.feature.fields.forEach((field) => { form[field.name] = field.type === 'checkbox' ? field.example === 'true' : field.type === 'number' ? Number(field.example) || 0 : field.type === 'select' ? field.example : field.example.startsWith('{') ? field.example : ''; }); }
async function loadRows() {
  if (!domainId.value) return;
  const id = domainId.value;
  const data = await ({
    dns: () => dnsApi.list(id),
    redirects: () => redirectsApi.list(id),
    'page-rules': () => pageRulesApi.list(id),
    cache: async () => {
      const [rules, settings, analytics] = await Promise.all([cacheApi.rules(id), cacheApi.settings(id), cacheApi.analytics(id)]);
      return [{ ...settings, id: 'settings', kind: 'settings' }, ...rules.map((rule) => ({ ...rule, kind: 'rule' })), { ...analytics, id: 'analytics', kind: 'analytics' }] as unknown[];
    },
    purge: () => purgeApi.list(id),
    security: () => wafApi.list(id),
    'rate-limit': () => rateLimitApi.list(id),
    ssl: () => sslApi.certificates(id),
  }[props.feature.key]()).catch((error) => { lastResult.value = error instanceof Error ? error.message : error; return []; });
  rows.value = Array.isArray(data) ? data.filter(Boolean) as Record<string, unknown>[] : [data as Record<string, unknown>];
  if (props.feature.key === 'dns') Object.assign(routingForm, await dnsApi.routing(id));
  if (props.feature.key === 'cache') {
    const settings = rows.value.find((row) => row.kind === 'settings');
    if (settings) Object.assign(form, settings);
  }
}
async function saveRouting() {
  if (!domainId.value) return;
  const current = await dnsApi.routing(domainId.value);
  if (current.routing_mode !== routingForm.routing_mode && !window.confirm(`Switch routing from ${current.routing_mode} to ${routingForm.routing_mode}? Existing proxied DNS records will be republished.`)) return;
  saving.value = true; formError.value = '';
  try { lastResult.value = await dnsApi.updateRouting(domainId.value, routingForm); await loadRows(); }
  catch (error) { formError.value = error instanceof Error ? error.message : 'Unable to update routing.'; }
  finally { saving.value = false; }
}
async function toggleDnsProxy(row: Record<string, unknown>) {
  if (!domainId.value) return;
  saving.value = true;
  try { lastResult.value = await dnsApi.update(domainId.value, String(row.id), { proxied: !row.proxied }); await loadRows(); }
  catch (error) { formError.value = error instanceof Error ? error.message : 'Unable to update proxy mode.'; }
  finally { saving.value = false; }
}
async function previewDns(row: Record<string, unknown>) {
  if (!domainId.value) return;
  try { lastResult.value = await dnsApi.previewRouting(domainId.value, String(row.id)); }
  catch (error) { lastResult.value = error instanceof Error ? error.message : error; }
}
async function submit() {
  if (!domainId.value) return; saving.value = true; formError.value = '';
  const input = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : v]));
  if (props.feature.key === 'purge' && ['url', 'prefix'].includes(String(input.type)) && !String(input.value ?? '').trim()) {
    formError.value = 'URL or prefix is required for this purge scope.';
    saving.value = false;
    return;
  }
  try {
    const id = domainId.value;
    lastResult.value = await ({
      dns: () => editingId.value ? dnsApi.update(id, editingId.value, input as Partial<DnsRecord>) : dnsApi.create(id, input as never),
      redirects: () => editingId.value ? redirectsApi.update(id, editingId.value, input as Partial<RedirectRule>) : redirectsApi.create(id, input as Partial<RedirectRule>),
      'page-rules': () => editingId.value ? pageRulesApi.update(id, editingId.value, { ...input, actions: parseMaybeJson(String(input.actions ?? '{}')) } as Partial<PageRule>) : pageRulesApi.create(id, { ...input, actions: parseMaybeJson(String(input.actions ?? '{}')) }),
      cache: () => cacheApi.updateSettings(id, cacheSettingsInput(input)),
      purge: () => purgeApi.create(id, input as { type: string; value?: string }),
      security: () => editingId.value ? wafApi.update(id, editingId.value, input as Partial<WafRule>) : wafApi.create(id, input),
      'rate-limit': () => editingId.value ? rateLimitApi.update(id, editingId.value, input) : rateLimitApi.create(id, input as never),
      ssl: () => sslApi.manualCertificate(id, input as never),
    }[props.feature.key] as () => Promise<unknown>)();
    if (editingId.value) cancelEdit();
    showForm.value = false;
    await loadRows();
  } catch (error) {
    console.error('[feature-form]', error);
    formError.value = error instanceof Error ? error.message : 'Unable to save this configuration.';
    lastResult.value = formError.value;
  } finally { saving.value = false; }
}
function editRow(row: Record<string, unknown>) {
  editingId.value = String(row.id ?? '');
  showForm.value = true;
  props.feature.fields.forEach((field) => {
    const value = field.name === 'actions' && row.actions ? JSON.stringify(row.actions, null, 2) : row[field.name];
    form[field.name] = typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? value : '';
  });
}
async function toggleRedirect(row: Record<string, unknown>) {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    lastResult.value = await redirectsApi.update(domainId.value, String(row.id), { enabled: !row.enabled });
    await loadRows();
  } catch (error) {
    console.error('[redirect-toggle]', error);
    formError.value = error instanceof Error ? error.message : 'Unable to update redirect.';
  } finally { saving.value = false; }
}
function canEdit(row: Record<string, unknown>) { return ['dns', 'redirects', 'page-rules', 'security', 'rate-limit'].includes(props.feature.key) || (props.feature.key === 'cache' && row.kind === 'rule'); }
function canDelete(row: Record<string, unknown>) { return ['dns', 'redirects', 'page-rules', 'security', 'rate-limit'].includes(props.feature.key) || (props.feature.key === 'cache' && row.kind === 'rule'); }
async function deleteRow(row: Record<string, unknown>) {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const id = domainId.value; const rowId = String(row.id);
    const handlers: Partial<Record<FeatureKey, () => Promise<unknown>>> = {
      dns: () => dnsApi.remove(id, rowId),
      redirects: () => redirectsApi.remove(id, rowId),
      'page-rules': () => pageRulesApi.remove(id, rowId),
      cache: () => cacheApi.removeRule(id, rowId),
      security: () => wafApi.remove(id, rowId),
      'rate-limit': () => rateLimitApi.delete(id, rowId),
    };
    const handler = handlers[props.feature.key];
    if (!handler) return;
    lastResult.value = await handler();
    if (editingId.value === rowId) cancelEdit();
    await loadRows();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Unable to delete record.';
  } finally { saving.value = false; }
}
async function toggleRateLimit(row: Record<string, unknown>) {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    lastResult.value = await rateLimitApi.update(domainId.value, String(row.id), { enabled: !row.enabled });
    await loadRows();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Unable to update rate limit.';
  } finally { saving.value = false; }
}
async function exportRedirects() { if (!domainId.value) return; lastResult.value = await redirectsApi.exportRules(domainId.value); }
async function importRedirects() {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const item = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : v]));
    lastResult.value = await redirectsApi.importRules(domainId.value, { items: [item] });
    await loadRows();
  } catch (error) { formError.value = error instanceof Error ? error.message : 'Unable to import redirects.'; } finally { saving.value = false; }
}
async function testRedirect() {
  if (!domainId.value) return;
  lastResult.value = await redirectsApi.test(domainId.value, { path: String(form.source_path || '/'), query: '' });
}
async function testPageRule() {
  if (!domainId.value) return;
  lastResult.value = await pageRulesApi.test(domainId.value, { path: String(form.pattern || '/') });
}
async function saveCacheRule() {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const input = { enabled: Boolean(form.enabled), path_prefix: String(form.path_prefix || '/'), ttl_seconds: Number(form.ttl_seconds || form.default_edge_ttl_seconds || 60) };
    lastResult.value = editingId.value ? await cacheApi.updateRule(domainId.value, editingId.value, input as Partial<CacheRule>) : await cacheApi.createRule(domainId.value, input);
    cancelEdit();
    await loadRows();
  } catch (error) { formError.value = error instanceof Error ? error.message : 'Unable to save cache rule.'; } finally { saving.value = false; }
}
async function checkSsl() {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const hostname = String(form.hostname || selectedDomain.value?.domain || '').trim();
    lastResult.value = await sslApi.check(domainId.value, hostname ? { hostnames: [hostname] } : { hostnames: [] });
    await loadRows();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Unable to run SSL check.';
    lastResult.value = formError.value;
  } finally { saving.value = false; }
}
async function requestSsl() {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const hostname = String(form.hostname || selectedDomain.value?.domain || '').trim();
    lastResult.value = await sslApi.request(domainId.value, hostname ? { hostnames: [hostname] } : { hostnames: [] });
    await loadRows();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Unable to request SSL.';
    lastResult.value = formError.value;
  } finally { saving.value = false; }
}
async function issueAcme() {
  if (!domainId.value) return;
  saving.value = true; formError.value = '';
  try {
    const hostname = String(form.hostname || selectedDomain.value?.domain || '').trim();
    lastResult.value = await sslApi.issueAcme(domainId.value, hostname ? { hostnames: [hostname] } : { hostnames: [] });
    await loadRows();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Unable to issue ACME SSL.';
    lastResult.value = formError.value;
  } finally { saving.value = false; }
}
async function loadSecurityEvents() {
  if (!domainId.value) return;
  lastResult.value = await wafApi.events(domainId.value, { type: String(form.type || ''), limit: 100 });
}
async function getPurgeRequest(row: Record<string, unknown>) {
  if (!domainId.value) return;
  lastResult.value = await purgeApi.get(domainId.value, String(row.id));
}
function cacheSettingsInput(input: Record<string, unknown>) {
  const browserTtl = input.default_browser_ttl_seconds === '' || input.default_browser_ttl_seconds === null || input.default_browser_ttl_seconds === undefined ? null : Number(input.default_browser_ttl_seconds);
  return {
    enabled: Boolean(input.enabled),
    default_edge_ttl_seconds: Number(input.default_edge_ttl_seconds || 3600),
    default_browser_ttl_seconds: browserTtl,
    cache_query_string_mode: String(input.cache_query_string_mode || 'include_all'),
    respect_origin_cache_control: Boolean(input.respect_origin_cache_control),
    cache_authorized_requests: Boolean(input.cache_authorized_requests),
    stale_if_error_seconds: Number(input.stale_if_error_seconds || 0),
  };
}
function startCreate() { editingId.value = ''; resetForm(); showForm.value = true; }
function cancelEdit() { editingId.value = ''; showForm.value = false; resetForm(); }
function parseMaybeJson(text: string) { try { return JSON.parse(text); } catch { return {}; } }
watch(domainId, () => { cancelEdit(); loadRows(); }); watch(() => props.feature.key, () => { cancelEdit(); rows.value = []; });
onMounted(async () => { resetForm(); domains.value = await domainsApi.list().catch(() => []); domainId.value = domains.value[0]?.id ?? ''; });
</script>
