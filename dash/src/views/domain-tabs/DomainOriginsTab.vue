<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>Origins</h2><p>Monitor primary and backup origin health for this domain.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add backup</button>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit origin' : 'Add origin' }}</h2><p>The edge retries the first enabled backup when the primary returns 502, 503, or 504.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div class="grid gap-4 md:grid-cols-3">
        <label><span class="field-label">Scheme</span><select v-model="form.scheme" class="input"><option value="http">HTTP</option><option value="https">HTTPS</option></select></label>
        <label class="md:col-span-2"><span class="field-label">Host</span><input v-model="form.host" class="input" placeholder="origin.example.com" /></label>
        <label><span class="field-label">Port</span><select v-model.number="form.port" class="input"><option :value="80">80</option><option :value="443">443</option></select></label>
        <label><span class="field-label">Health path</span><input v-model="form.health_check_path" class="input" placeholder="/" /></label>
        <label><span class="field-label">Timeout</span><input v-model.number="form.health_check_timeout_seconds" class="input" type="number" min="1" max="60" /></label>
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
      </div>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save origin' }}</button>
      </div>
    </form>

    <EmptyState v-if="!loading && rows.length === 0" title="No origins yet" message="Create a proxied DNS record or add an origin manually." />
    <DataTable v-else title="Origin health" :rows="rows" :columns="columns">
      <template #is_primary="{ row }"><StatusBadge :status="row.is_primary ? 'info' : 'unknown'" :label="row.is_primary ? 'Primary' : 'Backup'" /></template>
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
import type { DomainOrigin, Severity } from '@/types';

const props = defineProps<{ domainId: string }>();
const rows = ref<DomainOrigin[]>([]);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const editingId = ref('');
const message = ref('');
const form = reactive({
  scheme: 'http' as 'http' | 'https',
  host: '',
  port: 80,
  is_primary: false,
  health_check_path: '/',
  health_check_interval_seconds: 30,
  health_check_timeout_seconds: 5,
  enabled: true,
});
const columns = [
  { key: 'is_primary', label: 'Role' }, { key: 'host', label: 'Host' }, { key: 'port', label: 'Port' },
  { key: 'health_status', label: 'Health' }, { key: 'last_check_at', label: 'Last checked' },
  { key: 'enabled', label: 'Enabled' }, { key: 'actions', label: 'Actions' },
];

function reset() { Object.assign(form, { scheme: 'http', host: '', port: 80, is_primary: false, health_check_path: '/', health_check_interval_seconds: 30, health_check_timeout_seconds: 5, enabled: true }); }
function healthSeverity(status: string): Severity { if (status === 'healthy') return 'healthy'; if (status === 'unhealthy') return 'critical'; return 'unknown'; }
function formatTime(value: number) { return new Date(value * 1000).toLocaleString(); }
async function load() { loading.value = true; try { rows.value = await originsApi.list(props.domainId); } finally { loading.value = false; } }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); Object.assign(form, { scheme: String(row.scheme ?? 'http'), host: String(row.host ?? ''), port: Number(row.port ?? 80), is_primary: Boolean(row.is_primary), health_check_path: String(row.health_check_path ?? '/'), health_check_interval_seconds: Number(row.health_check_interval_seconds ?? 30), health_check_timeout_seconds: Number(row.health_check_timeout_seconds ?? 5), enabled: Boolean(row.enabled) }); editing.value = true; }
async function save() { saving.value = true; try { editingId.value ? await originsApi.update(props.domainId, editingId.value, form) : await originsApi.create(props.domainId, form); editing.value = false; message.value = 'Origin saved.'; await load(); } finally { saving.value = false; } }
async function toggle(row: Record<string, unknown>) { await originsApi.update(props.domainId, String(row.id), { enabled: !row.enabled }); await load(); }
async function check(row: Record<string, unknown>) { await originsApi.check(props.domainId, String(row.id)); message.value = 'Origin health checked.'; await load(); }
async function remove(row: Record<string, unknown>) { await originsApi.remove(props.domainId, String(row.id)); message.value = 'Origin deleted.'; await load(); }
watch(() => props.domainId, load); onMounted(load);
</script>
