<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black text-white">{{ feature.title }}</h1>
      <p class="text-slate-400">{{ feature.subtitle }}</p>
    </div>
    <div class="card p-5">
      <div class="grid gap-4 md:grid-cols-[280px_1fr]">
        <label class="space-y-2">
          <span class="text-sm font-semibold text-slate-200">Site</span>
          <select v-model="siteId" class="input"><option value="">Select a site…</option><option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }} — {{ site.domain }}</option></select>
          <p class="text-xs text-slate-400">All APIs in this section are site-scoped. Select the site first.</p>
        </label>
        <div class="rounded-xl border border-white/10 bg-slate-950/50 p-4">
          <p class="mb-2 text-sm font-semibold text-slate-200">Supported endpoints</p>
          <ul class="list-disc space-y-1 pl-5 text-sm text-slate-400"><li v-for="endpoint in feature.endpointSummary" :key="endpoint"><code>{{ endpoint }}</code></li></ul>
        </div>
      </div>
    </div>
    <form class="card grid gap-4 p-5 xl:grid-cols-2" @submit.prevent="submit">
      <template v-for="field in feature.fields" :key="field.name">
        <label v-if="field.type === 'checkbox'" class="space-y-2 rounded-xl border border-white/10 p-4">
          <div class="flex items-center gap-3"><input v-model="form[field.name]" type="checkbox" class="h-4 w-4" /><span class="font-semibold text-white">{{ field.label }}</span></div>
          <div class="text-xs text-slate-400"><p><b>What this is:</b> {{ field.what }}</p><p><b>How this works:</b> {{ field.works }}</p><p><b>Example:</b> <code>{{ field.example }}</code></p></div>
        </label>
        <TextareaInput v-else-if="field.type === 'textarea'" v-model="form[field.name]" :help="toHelp(field)" />
        <TextInput v-else v-model="form[field.name]" :type="field.type === 'number' ? 'number' : 'text'" :help="toHelp(field)" />
      </template>
      <div class="xl:col-span-2 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-amber-200" v-if="feature.key === 'purge'">Purge type “everything” can invalidate all cached content for the selected site.</p>
        <button class="button-primary" :disabled="!siteId || saving">Save / Run {{ feature.title }}</button>
      </div>
    </form>
    <div class="grid gap-4 xl:grid-cols-2">
      <DataTable :title="`${feature.title} Records`" :rows="rows" :columns="columns" />
      <div class="card p-5">
        <h3 class="font-semibold text-white">Raw JSON / Test Result</h3>
        <pre class="mt-4 max-h-[520px] overflow-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-300">{{ JSON.stringify(lastResult, null, 2) }}</pre>
      </div>
    </div>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import TextInput from '@/components/forms/TextInput.vue';
import TextareaInput from '@/components/forms/TextareaInput.vue';
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
import type { Site } from '@/types';
import type { FeaturePage } from './featurePages';
const props = defineProps<{ feature: FeaturePage }>();
const sites = ref<Site[]>([]); const siteId = ref(''); const rows = ref<Record<string, unknown>[]>([]); const saving = ref(false); const lastResult = ref<unknown>(null); const form = reactive<Record<string, string | number | boolean>>({});
const columns = computed(() => Object.keys(rows.value[0] ?? { id: '', name: '', status: '' }).slice(0, 8).map((key) => ({ key, label: key.replaceAll('_', ' ') })));
function toHelp(field: FeaturePage['fields'][number]) { return { label: field.label, what: field.what, works: field.works, example: field.example, required: field.required }; }
function resetForm() { props.feature.fields.forEach((field) => { form[field.name] = field.type === 'checkbox' ? false : field.type === 'number' ? Number(field.example) || 0 : field.example.startsWith('{') ? field.example : ''; }); }
async function loadRows() {
  if (!siteId.value) return;
  const id = siteId.value;
  const data = await ({
    dns: () => dnsApi.list(id), redirects: () => redirectsApi.list(id), 'page-rules': () => pageRulesApi.list(id), cache: async () => [...await cacheApi.rules(id), await cacheApi.settings(id), await cacheApi.analytics(id)] as unknown[], purge: () => purgeApi.list(id), security: () => wafApi.list(id), 'rate-limit': async () => [await rateLimitApi.get(id)], ssl: () => sslApi.certificates(id),
  }[props.feature.key]()).catch((error) => { lastResult.value = error instanceof Error ? error.message : error; return []; });
  rows.value = Array.isArray(data) ? data.filter(Boolean) as Record<string, unknown>[] : [data as Record<string, unknown>];
}
async function submit() {
  if (!siteId.value) return; saving.value = true;
  const input = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : v]));
  try {
    const id = siteId.value;
    lastResult.value = await ({
      dns: () => dnsApi.create(id, input as never), redirects: () => redirectsApi.create(id, input), 'page-rules': () => pageRulesApi.create(id, { ...input, actions: parseMaybeJson(String(input.actions ?? '{}')) }), cache: () => cacheApi.updateSettings(id, input as never), purge: () => purgeApi.create(id, input as { type: string; value?: string }), security: () => wafApi.create(id, input), 'rate-limit': () => rateLimitApi.save(id, input as never), ssl: () => sslApi.manualCertificate(id, input as never),
    }[props.feature.key]());
    await loadRows();
  } finally { saving.value = false; }
}
function parseMaybeJson(text: string) { try { return JSON.parse(text); } catch { return {}; } }
watch(siteId, loadRows); watch(() => props.feature.key, () => { resetForm(); rows.value = []; });
onMounted(async () => { resetForm(); sites.value = await sitesApi.list().catch(() => []); siteId.value = sites.value[0]?.id ?? ''; });
</script>
