<template>
  <section class="space-y-5">
    <form class="panel-section" @submit.prevent="saveSettings">
      <div class="section-heading">
        <div><h2>Cache configuration</h2><p>Set the default behavior for content stored at the edge.</p></div>
        <StatusBadge :status="settings.enabled ? 'healthy' : 'unknown'" :label="settings.enabled ? 'Enabled' : 'Disabled'" />
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Best default</b><span>Enable edge cache, respect origin Cache-Control, and use 1 hour TTL for ordinary static assets.</span></div>
        <div class="help-item"><b>Dynamic pages</b><span>Keep sensitive or personalized paths uncached with a short cache rule or origin headers.</span></div>
        <div class="help-item"><b>Stale content</b><span>Use stale-if-error so visitors can still receive cached content during an origin outage.</span></div>
      </div>
      <div class="divide-y divide-slate-100 dark:divide-white/5">
        <label class="setting-row border-0 px-0">
          <span><b>Edge cache</b><small>Store eligible origin responses on CDNLite edge nodes.</small></span>
          <input v-model="settings.enabled" class="toggle" type="checkbox" />
        </label>
        <label class="setting-row border-0 px-0">
          <span><b>Respect origin Cache-Control</b><small>Use cache directives returned by your origin when present.</small></span>
          <input v-model="settings.respect_origin_cache_control" class="toggle" type="checkbox" />
        </label>
        <div class="grid gap-4 py-4 md:grid-cols-2">
          <label><span class="field-label">Default edge TTL</span><select v-model.number="settings.default_edge_ttl_seconds" class="input"><option :value="60">1 minute</option><option :value="300">5 minutes</option><option :value="3600">1 hour</option><option :value="14400">4 hours</option><option :value="86400">1 day</option><option :value="604800">7 days</option></select></label>
          <label><span class="field-label">Stale if origin fails</span><select v-model.number="settings.stale_if_error_seconds" class="input"><option :value="0">Disabled</option><option :value="3600">1 hour</option><option :value="86400">1 day</option><option :value="604800">7 days</option></select></label>
        </div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
        <p v-if="message" class="mr-auto text-sm text-emerald-700">{{ message }}</p>
        <button class="button-primary" :disabled="saving"><Save class="h-4 w-4" /> {{ saving ? 'Saving...' : 'Save settings' }}</button>
      </div>
    </form>

    <section class="panel-section">
      <div class="section-heading">
        <div><h2>Purge cache</h2><p>Remove cached content from every edge node.</p></div>
        <Trash2 class="h-5 w-5 text-slate-400" />
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Exact URL</b><span>Use a full URL such as https://example.com/app.css after deploying one changed file.</span></div>
        <div class="help-item"><b>Path prefix</b><span>Use /assets/ or /images/ when a whole folder changed.</span></div>
        <div class="help-item"><b>Purge everything</b><span>Use sparingly. It clears all cached files and can increase origin load.</span></div>
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <button class="purge-option" @click="purge('everything')"><RefreshCcw class="h-5 w-5 text-rose-600" /><span><b>Purge everything</b><small>Clear all cached files for this domain.</small></span></button>
        <form class="purge-option lg:col-span-2" @submit.prevent="purge(purgeType)">
          <Link2 class="h-5 w-5 text-cyan-700" />
          <div class="min-w-0 flex-1">
            <div class="mb-2 flex gap-4 text-sm"><label><input v-model="purgeType" type="radio" value="url" /> Exact URL</label><label><input v-model="purgeType" type="radio" value="prefix" /> Path prefix</label></div>
            <div class="flex flex-col gap-2 sm:flex-row"><input v-model="purgeValue" class="input" :placeholder="purgeType === 'url' ? 'https://example.com/app.js' : '/assets/'" /><button class="button-secondary">Purge</button></div>
          </div>
        </form>
      </div>
    </section>

    <DomainRulesTab :domain-id="domainId" title="Cache Rules" summary="Override the edge TTL for matching path prefixes." :fields="fields" :columns="columns" :help-items="ruleHelpItems" :list="() => cacheApi.rules(domainId)" :create="(value) => cacheApi.createRule(domainId, value)" :update="(id, value) => cacheApi.updateRule(domainId, id, value)" :remove="(id) => cacheApi.removeRule(domainId, id)" />
    <DataTable title="Recent purges" subtitle="Cache invalidation requests and their current state." :rows="purges" :columns="purgeColumns">
      <template #status="{ row }"><StatusBadge :status="row.status === 'completed' ? 'healthy' : row.status === 'failed' ? 'critical' : 'warning'" :label="String(row.status || 'pending')" /></template>
    </DataTable>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { Link2, RefreshCcw, Save, Trash2 } from 'lucide-vue-next';
import DomainRulesTab from './DomainRulesTab.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { cacheApi } from '@/lib/api/cache';
import { purgeApi } from '@/lib/api/purge';

const props = defineProps<{ domainId: string }>();
const settings = reactive({ enabled: true, default_edge_ttl_seconds: 3600, default_browser_ttl_seconds: null as number | null, cache_query_string_mode: 'include_all', respect_origin_cache_control: true, cache_authorized_requests: false, stale_if_error_seconds: 86400 });
const purges = ref<Record<string, unknown>[]>([]);
const purgeValue = ref('');
const purgeType = ref('url');
const saving = ref(false);
const message = ref('');
const ruleHelpItems = [{ title: 'Prefix examples', body: 'Use /assets/ for static files, /api/ for API responses, or / for the whole site.' }, { title: 'TTL examples', body: '300 is 5 minutes, 3600 is 1 hour, 86400 is 1 day.' }, { title: 'Rule order', body: 'Use narrower prefixes when overriding the default cache behavior.' }];
const fields = [{ key: 'enabled', label: 'Enabled', type: 'checkbox' as const, default: true, help: 'Keep disabled while drafting a cache override.' }, { key: 'path_prefix', label: 'Path prefix', default: '/', placeholder: '/assets/', help: 'Match requests whose path starts with this value.' }, { key: 'ttl_seconds', label: 'TTL seconds', type: 'number' as const, default: 3600, placeholder: '3600', help: 'How long matching responses should stay cached at the edge.' }];
const columns = [{ key: 'enabled', label: 'Status' }, { key: 'path_prefix', label: 'Path' }, { key: 'ttl_seconds', label: 'Edge TTL' }, { key: 'actions', label: '' }];
const purgeColumns = [{ key: 'type', label: 'Scope' }, { key: 'value', label: 'Target' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Created' }];

async function load() {
  const [currentSettings, currentPurges] = await Promise.all([cacheApi.settings(props.domainId), purgeApi.list(props.domainId)]);
  Object.assign(settings, currentSettings);
  purges.value = currentPurges as unknown as Record<string, unknown>[];
}
async function saveSettings() {
  saving.value = true;
  message.value = '';
  try { Object.assign(settings, await cacheApi.updateSettings(props.domainId, settings)); message.value = 'Cache settings saved.'; }
  finally { saving.value = false; }
}
async function purge(type: string) {
  if (type !== 'everything' && !purgeValue.value.trim()) return;
  if (!window.confirm(`Purge ${type === 'everything' ? 'all cached content' : purgeValue.value}?`)) return;
  await purgeApi.create(props.domainId, { type, value: type === 'everything' ? undefined : purgeValue.value.trim() });
  purgeValue.value = '';
  await load();
}
watch(() => props.domainId, load);
onMounted(load);
</script>
