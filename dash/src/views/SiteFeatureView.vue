<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">{{ feature.title }}</h1>
      <p class="text-slate-600 dark:text-slate-400">{{ feature.subtitle }}</p>
    </div>
    <div class="card p-5">
      <div class="grid gap-4 md:grid-cols-[280px_1fr]">
        <label class="space-y-2">
          <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Site</span>
          <select v-model="siteId" class="input"><option value="">Select a site…</option><option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }} — {{ site.domain }}</option></select>
          <p class="text-xs text-slate-500 dark:text-slate-400">All APIs in this section are site-scoped. Select the site first.</p>
        </label>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-slate-950/50">
          <p class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">Supported endpoints</p>
          <ul class="list-disc space-y-1 pl-5 text-sm text-slate-500 dark:text-slate-400"><li v-for="endpoint in feature.endpointSummary" :key="endpoint"><code>{{ endpoint }}</code></li></ul>
        </div>
      </div>
    </div>
    <form class="card grid gap-4 p-5 xl:grid-cols-2" @submit.prevent="submit">
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
        <p class="text-sm text-amber-200" v-if="feature.key === 'purge'">Purge type “everything” can invalidate all cached content for the selected site.</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="editingId" type="button" class="button-secondary" @click="cancelEdit">Cancel edit</button>
          <button class="button-primary" :disabled="!siteId || saving">{{ editingId ? 'Save changes' : submitLabel }}</button>
        </div>
      </div>
    </form>
    <div class="grid gap-4 xl:grid-cols-2">
      <DataTable :title="`${feature.title} Records`" :rows="rows" :columns="columns">
        <template #enabled="{ row }">
          <button v-if="feature.key === 'redirects'" class="button-secondary px-2 py-1 text-xs" @click="toggleRedirect(row)">{{ row.enabled ? 'Disable' : 'Enable' }}</button>
          <span v-else>{{ String(row.enabled ?? '') }}</span>
        </template>
        <template #actions="{ row }">
          <div v-if="feature.key === 'redirects'" class="flex flex-wrap gap-2">
            <button class="button-secondary px-2 py-1 text-xs" @click="editRedirect(row)">Edit</button>
            <ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this redirect rule?" @confirm="deleteRedirect(row)">Delete</ConfirmDangerButton>
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
import { sitesApi } from '@/lib/api/sites';
import { sslApi } from '@/lib/api/ssl';
import { wafApi } from '@/lib/api/waf';
import type { RedirectRule, Site } from '@/types';
import type { FeaturePage } from './featurePages';
const props = defineProps<{ feature: FeaturePage }>();
const sites = ref<Site[]>([]); const siteId = ref(''); const rows = ref<Record<string, unknown>[]>([]); const saving = ref(false); const lastResult = ref<unknown>(null); const formError = ref(''); const editingId = ref(''); const form = reactive<Record<string, string | number | boolean>>({});
const columns = computed(() => {
  const base = Object.keys(rows.value[0] ?? { id: '', name: '', status: '' }).slice(0, 8).map((key) => ({ key, label: key.replaceAll('_', ' ') }));
  return props.feature.key === 'redirects' ? [...base.filter((column) => column.key !== 'actions'), { key: 'actions', label: 'Actions' }] : base;
});
const submitLabel = computed(() => props.feature.key === 'purge' ? 'Purge cache' : `Save / Run ${props.feature.title}`);
function toHelp(field: FeaturePage['fields'][number]) { return { label: field.label, what: field.what, works: field.works, example: field.example, required: field.required }; }
function resetForm() { props.feature.fields.forEach((field) => { form[field.name] = field.type === 'checkbox' ? field.example === 'true' : field.type === 'number' ? Number(field.example) || 0 : field.example.startsWith('{') ? field.example : ''; }); }
async function loadRows() {
  if (!siteId.value) return;
  const id = siteId.value;
  const data = await ({
    dns: () => dnsApi.list(id), redirects: () => redirectsApi.list(id), 'page-rules': () => pageRulesApi.list(id), cache: async () => [...await cacheApi.rules(id), await cacheApi.settings(id), await cacheApi.analytics(id)] as unknown[], purge: () => purgeApi.list(id), security: () => wafApi.list(id), 'rate-limit': async () => [await rateLimitApi.get(id)], ssl: () => sslApi.certificates(id),
  }[props.feature.key]()).catch((error) => { lastResult.value = error instanceof Error ? error.message : error; return []; });
  rows.value = Array.isArray(data) ? data.filter(Boolean) as Record<string, unknown>[] : [data as Record<string, unknown>];
  if (props.feature.key === 'rate-limit' && rows.value[0]) Object.assign(form, rows.value[0]);
}
async function submit() {
  if (!siteId.value) return; saving.value = true; formError.value = '';
  const input = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : v]));
  if (props.feature.key === 'purge' && ['url', 'prefix'].includes(String(input.type)) && !String(input.value ?? '').trim()) {
    formError.value = 'URL or prefix is required for this purge scope.';
    saving.value = false;
    return;
  }
  try {
    const id = siteId.value;
    lastResult.value = await ({
      dns: () => dnsApi.create(id, input as never), redirects: () => editingId.value ? redirectsApi.update(id, editingId.value, input as Partial<RedirectRule>) : redirectsApi.create(id, input as Partial<RedirectRule>), 'page-rules': () => pageRulesApi.create(id, { ...input, actions: parseMaybeJson(String(input.actions ?? '{}')) }), cache: () => cacheApi.updateSettings(id, input as never), purge: () => purgeApi.create(id, input as { type: string; value?: string }), security: () => wafApi.create(id, input), 'rate-limit': () => rateLimitApi.save(id, input as never), ssl: () => sslApi.manualCertificate(id, input as never),
    }[props.feature.key]());
    if (editingId.value) cancelEdit();
    await loadRows();
  } catch (error) {
    console.error('[feature-form]', error);
    formError.value = error instanceof Error ? error.message : 'Unable to save this configuration.';
    lastResult.value = formError.value;
  } finally { saving.value = false; }
}
function editRedirect(row: Record<string, unknown>) {
  editingId.value = String(row.id ?? '');
  props.feature.fields.forEach((field) => {
    const value = row[field.name];
    form[field.name] = typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? value : '';
  });
}
async function toggleRedirect(row: Record<string, unknown>) {
  if (!siteId.value) return;
  saving.value = true; formError.value = '';
  try {
    lastResult.value = await redirectsApi.update(siteId.value, String(row.id), { enabled: !row.enabled });
    await loadRows();
  } catch (error) {
    console.error('[redirect-toggle]', error);
    formError.value = error instanceof Error ? error.message : 'Unable to update redirect.';
  } finally { saving.value = false; }
}
async function deleteRedirect(row: Record<string, unknown>) {
  if (!siteId.value) return;
  saving.value = true; formError.value = '';
  try {
    lastResult.value = await redirectsApi.remove(siteId.value, String(row.id));
    if (editingId.value === String(row.id)) cancelEdit();
    await loadRows();
  } catch (error) {
    console.error('[redirect-delete]', error);
    formError.value = error instanceof Error ? error.message : 'Unable to delete redirect.';
  } finally { saving.value = false; }
}
function cancelEdit() { editingId.value = ''; resetForm(); }
function parseMaybeJson(text: string) { try { return JSON.parse(text); } catch { return {}; } }
watch(siteId, () => { cancelEdit(); loadRows(); }); watch(() => props.feature.key, () => { cancelEdit(); rows.value = []; });
onMounted(async () => { resetForm(); sites.value = await sitesApi.list().catch(() => []); siteId.value = sites.value[0]?.id ?? ''; });
</script>
