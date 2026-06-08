<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>Headers</h2><p>Set, append, or remove response headers for matching paths.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add header</button>
    </div>

    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
      <button v-for="preset in presets" :key="preset.header_name" class="button-secondary justify-center" @click="quickAdd(preset)">
        {{ preset.label }}
      </button>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit header rule' : 'Add header rule' }}</h2><p>Rules run by priority before the response leaves the edge.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Security presets</b><span>Use the quick buttons for common headers, then adjust values if your app needs third-party scripts or framing.</span></div>
        <div class="help-item"><b>Operation choice</b><span>Set replaces existing values, Append adds another value, and Remove strips the header.</span></div>
        <div class="help-item"><b>Scope carefully</b><span>Start with /* for global security headers, or a narrower path such as /assets/* for file-specific headers.</span></div>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <label><span class="field-label">Operation</span><select v-model="form.operation" class="input"><option value="set">Set</option><option value="append">Append</option><option value="remove">Remove</option></select><span class="field-description">Best practice: use Set unless you specifically need multiple header values.</span></label>
        <label><span class="field-label">Header name</span><input v-model="form.header_name" class="input" placeholder="Strict-Transport-Security" /><span class="field-description">Use the exact HTTP header name, for example Content-Security-Policy.</span></label>
        <label class="md:col-span-2"><span class="field-label">Header value</span><input v-model="form.header_value" class="input" :disabled="form.operation === 'remove'" placeholder="max-age=31536000; includeSubDomains" /><span class="field-description">Leave empty only when removing a header.</span></label>
        <label><span class="field-label">Path pattern</span><input v-model="form.path_pattern" class="input" placeholder="/*" /><span class="field-description">Use /* for every path or a narrower pattern such as /downloads/*.</span></label>
        <label><span class="field-label">Priority</span><input v-model.number="form.priority" type="number" class="input" placeholder="100" /><span class="field-description">Lower numbers run first when multiple header rules match.</span></label>
      </div>
      <label class="setting-row mt-5">
        <span><b>Enabled</b><small>Turn this rule on without changing its configuration.</small></span>
        <input v-model="form.enabled" class="toggle" type="checkbox" />
      </label>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save header' }}</button>
      </div>
    </form>

    <EmptyState v-if="!loading && rows.length === 0" title="No headers yet" message="Add a header rule or use a preset." />
    <DataTable v-else title="Header rules" :rows="rows" :columns="columns">
      <template #enabled="{ row }"><button class="rounded-full focus:outline-none focus:ring-4 focus:ring-cyan-500/20" @click="toggle(row)"><StatusBadge :status="row.enabled ? 'healthy' : 'disabled'" :label="row.enabled ? 'Enabled' : 'Disabled'" /></button></template>
      <template #operation="{ value }"><StatusBadge :status="String(value) === 'remove' ? 'warning' : 'info'" :label="String(value)" /></template>
      <template #actions="{ row }"><div class="flex gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="startEdit(row)">Edit</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this header rule?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
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
import { headerRulesApi } from '@/lib/api/headerRules';
import type { HeaderRule } from '@/types';

const props = defineProps<{ domainId: string }>();
const rows = ref<HeaderRule[]>([]);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const editingId = ref('');
const message = ref('');
const form = reactive({ enabled: true, priority: 100, operation: 'set', header_name: '', header_value: '', path_pattern: '/*' });
const columns = [
  { key: 'enabled', label: 'Enabled' }, { key: 'operation', label: 'Operation' }, { key: 'header_name', label: 'Header' },
  { key: 'header_value', label: 'Value' }, { key: 'path_pattern', label: 'Path' }, { key: 'actions', label: 'Actions' },
];
const presets = [
  { label: 'HSTS', operation: 'set', header_name: 'Strict-Transport-Security', header_value: 'max-age=31536000; includeSubDomains', path_pattern: '/*', priority: 10, enabled: true },
  { label: 'CSP', operation: 'set', header_name: 'Content-Security-Policy', header_value: "default-src 'self'", path_pattern: '/*', priority: 20, enabled: true },
  { label: 'X-Frame-Options', operation: 'set', header_name: 'X-Frame-Options', header_value: 'DENY', path_pattern: '/*', priority: 30, enabled: true },
  { label: 'X-Content-Type-Options', operation: 'set', header_name: 'X-Content-Type-Options', header_value: 'nosniff', path_pattern: '/*', priority: 40, enabled: true },
];

function reset() { Object.assign(form, { enabled: true, priority: 100, operation: 'set', header_name: '', header_value: '', path_pattern: '/*' }); }
async function load() { loading.value = true; try { rows.value = await headerRulesApi.list(props.domainId); } finally { loading.value = false; } }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); Object.assign(form, { enabled: Boolean(row.enabled), priority: Number(row.priority ?? 100), operation: String(row.operation ?? 'set'), header_name: String(row.header_name ?? ''), header_value: String(row.header_value ?? ''), path_pattern: String(row.path_pattern ?? '/*') }); editing.value = true; }
function payload() { return { ...form, header_value: form.operation === 'remove' ? null : form.header_value }; }
async function save() { saving.value = true; try { editingId.value ? await headerRulesApi.update(props.domainId, editingId.value, payload()) : await headerRulesApi.create(props.domainId, payload()); editing.value = false; message.value = 'Header rule saved.'; await load(); } finally { saving.value = false; } }
async function quickAdd(preset: Omit<HeaderRule, 'id'> & { label: string }) { const { label: _label, ...input } = preset; await headerRulesApi.create(props.domainId, input); message.value = 'Header preset added.'; await load(); }
async function toggle(row: Record<string, unknown>) { await headerRulesApi.update(props.domainId, String(row.id), { enabled: !row.enabled }); await load(); }
async function remove(row: Record<string, unknown>) { await headerRulesApi.remove(props.domainId, String(row.id)); message.value = 'Header rule deleted.'; await load(); }
watch(() => props.domainId, load); onMounted(load);
</script>
