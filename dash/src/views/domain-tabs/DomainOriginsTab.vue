<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>Origins</h2><p>Monitor DNS-linked and manually added origin health for this domain.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add backup</button>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit origin' : 'Add origin' }}</h2><p>The edge retries the first enabled backup when the primary returns 502, 503, or 504.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Host examples</b><span>Use origin.example.com or 192.0.2.10. Do not include http://, https://, or a path.</span></div>
        <div class="help-item"><b>Primary vs backup</b><span>Keep one healthy primary. DNS-linked origins stay visible here and backups are used after primary failures.</span></div>
        <div class="help-item"><b>Health checks</b><span>Use a lightweight path such as /health that returns 200 without expensive work.</span></div>
      </div>
      <div class="grid gap-4 md:grid-cols-3">
        <label><span class="field-label">Scheme</span><select v-model="form.scheme" class="input"><option value="http">HTTP</option><option value="https">HTTPS</option></select><span class="field-description">Use HTTPS when your origin has a valid certificate.</span></label>
        <label class="md:col-span-2"><span class="field-label">Host</span><input v-model="form.host" class="input" placeholder="origin.example.com" /><span class="field-description">Hostname or IP only, without protocol or path.</span></label>
        <label><span class="field-label">Port</span><select v-model.number="form.port" class="input"><option :value="80">80</option><option :value="443">443</option></select><span class="field-description">Use 443 for HTTPS and 80 for HTTP.</span></label>
        <label><span class="field-label">Host header</span><input v-model="form.host_header" class="input" placeholder="origin.example.com" /><span class="field-description">Leave blank to send the origin host.</span></label>
        <label><span class="field-label">SNI</span><input v-model="form.sni" class="input" placeholder="origin.example.com" /><span class="field-description">Leave blank to use the origin host for TLS SNI.</span></label>
        <label><span class="field-label">TLS verify</span><select v-model="form.tls_verify" class="input"><option value="verify">Verify</option><option value="ignore">Ignore</option></select><span class="field-description">Ignore only for private/test origins.</span></label>
        <label><span class="field-label">Health path</span><input v-model="form.health_check_path" class="input" placeholder="/health" /><span class="field-description">Must begin with / and should be cheap for the origin to serve.</span></label>
        <label><span class="field-label">Timeout</span><input v-model.number="form.health_check_timeout_seconds" class="input" type="number" min="1" max="60" /><span class="field-description">Use 5 seconds for most origins; raise only for slower backends.</span></label>
      </div>
      <div class="mt-5 grid gap-3 md:grid-cols-2">
        <label class="setting-row">
          <span><b>Primary</b><small>Primary origins are tried first.</small></span>
          <input v-model="form.is_primary" class="toggle" type="checkbox" />
        </label>
        <label class="setting-row">
          <span><b>Enabled</b><small>Disabled origins are ignored by the edge.</small></span>
          <input v-model="form.enabled" class="toggle" type="checkbox" />
        </label>
        <label class="setting-row">
          <span><b>Preserve CDN host</b><small>Send the visitor Host header instead of the origin host header.</small></span>
          <input v-model="form.preserve_host" class="toggle" type="checkbox" />
        </label>
      </div>
      <p v-if="error" class="state-error mt-4">{{ error }}</p>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save origin' }}</button>
      </div>
    </form>

    <EmptyState v-if="!loading && rows.length === 0" title="No origins yet" message="Create a proxied DNS record or add an origin manually." />
    <DataTable v-else title="Origin health" :rows="rows" :columns="columns">
      <template #is_primary="{ row }"><StatusBadge :status="row.is_primary ? 'info' : 'unknown'" :label="row.is_primary ? 'Primary' : 'Backup'" /></template>
      <template #source="{ row }">
        <div class="space-y-1">
          <StatusBadge :status="row.source === 'dns_record' ? 'info' : 'unknown'" :label="row.source === 'dns_record' ? 'DNS record' : 'Manual'" />
          <p v-if="row.dns_record_id" class="font-mono text-[11px] text-slate-500">{{ row.dns_record_id }}</p>
        </div>
      </template>
      <template #health_status="{ row }"><StatusBadge :status="healthSeverity(String(row.health_status))" :label="String(row.health_status)" /></template>
      <template #last_check_at="{ value }">{{ value ? formatTime(Number(value)) : 'Not checked' }}</template>
      <template #enabled="{ row }"><button class="rounded-full focus:outline-none focus:ring-4 focus:ring-cyan-500/20" @click="toggle(row)"><StatusBadge :status="row.enabled ? 'healthy' : 'unknown'" :label="row.enabled ? 'Enabled' : 'Disabled'" /></button></template>
      <template #actions="{ row }">
        <div class="flex flex-wrap gap-2">
          <button class="button-secondary px-2 py-1 text-xs" @click="check(row)">Check</button>
          <button class="button-secondary px-2 py-1 text-xs" @click="startEdit(row)">Edit</button>
          <ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this origin?" @confirm="remove(row)">Delete</ConfirmDangerButton>
        </div>
      </template>
    </DataTable>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { originsApi } from '@/lib/api/origins';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { DomainOrigin, Severity } from '@/types';

const props = defineProps<{ domainId: string }>();
const rows = ref<DomainOrigin[]>([]);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const editingId = ref('');
const message = ref('');
const error = ref('');
const form = reactive({
  scheme: 'http' as 'http' | 'https',
  host: '',
  port: 80,
  host_header: '',
  sni: '',
  tls_verify: 'verify' as 'verify' | 'ignore',
  preserve_host: false,
  is_primary: false,
  health_check_path: '/',
  health_check_interval_seconds: 30,
  health_check_timeout_seconds: 5,
  enabled: true,
});
const columns = [
  { key: 'is_primary', label: 'Role' }, { key: 'source', label: 'Source' }, { key: 'host', label: 'Host' }, { key: 'port', label: 'Port' },
  { key: 'health_status', label: 'Health' }, { key: 'last_check_at', label: 'Last checked' },
  { key: 'enabled', label: 'Enabled' }, { key: 'actions', label: 'Actions' },
];

function reset() { Object.assign(form, { scheme: 'http', host: '', port: 80, host_header: '', sni: '', tls_verify: 'verify', preserve_host: false, is_primary: false, health_check_path: '/', health_check_interval_seconds: 30, health_check_timeout_seconds: 5, enabled: true }); error.value = ''; }
function healthSeverity(status: string): Severity { if (status === 'healthy') return 'healthy'; if (status === 'unhealthy') return 'critical'; return 'unknown'; }
function formatTime(value: number) { return new Date(value * 1000).toLocaleString(); }
async function load() { loading.value = true; try { rows.value = await originsApi.list(props.domainId); } finally { loading.value = false; } }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); Object.assign(form, { scheme: String(row.scheme ?? 'http'), host: String(row.host ?? ''), port: Number(row.port ?? 80), host_header: String(row.host_header ?? ''), sni: String(row.sni ?? ''), tls_verify: String(row.tls_verify ?? 'verify'), preserve_host: Boolean(row.preserve_host), is_primary: Boolean(row.is_primary), health_check_path: String(row.health_check_path ?? '/'), health_check_interval_seconds: Number(row.health_check_interval_seconds ?? 30), health_check_timeout_seconds: Number(row.health_check_timeout_seconds ?? 5), enabled: Boolean(row.enabled) }); error.value = ''; editing.value = true; }
async function save() {
  error.value = '';
  const host = form.host.trim();
  if (!host) { error.value = 'Origin host is required.'; return; }
  if (/^https?:\/\//i.test(host) || host.includes('/')) { error.value = 'Enter a hostname or IP only, without protocol or path.'; return; }
  if (![80, 443].includes(Number(form.port))) { error.value = 'Port must be 80 or 443.'; return; }
  if (!form.health_check_path.startsWith('/')) { error.value = 'Health check path must start with /.'; return; }
  saving.value = true;
  try {
    editingId.value ? await originsApi.update(props.domainId, editingId.value, form) : await originsApi.create(props.domainId, form);
    editing.value = false;
    message.value = 'Origin saved.';
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to save origin.';
  } finally { saving.value = false; }
}
async function toggle(row: Record<string, unknown>) { await originsApi.update(props.domainId, String(row.id), { enabled: !row.enabled }); await load(); }
async function check(row: Record<string, unknown>) { await originsApi.check(props.domainId, String(row.id)); message.value = 'Origin health checked.'; await load(); }
async function remove(row: Record<string, unknown>) { await originsApi.remove(props.domainId, String(row.id)); message.value = 'Origin deleted.'; await load(); }
watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainOrigins(props.domainId)], load);
onMounted(load);
</script>
