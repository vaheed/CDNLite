<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>IP Access</h2><p>Allow or block visitor IPv4 CIDR ranges at the edge.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add rule</button>
    </div>

    <form class="panel-section" @submit.prevent="bulkImport">
      <div class="section-heading">
        <div><h2>Bulk import</h2><p>Paste one CIDR per line and choose how those ranges should be handled.</p></div>
      </div>
      <div class="help-panel">
        <div class="help-item"><b>CIDR examples</b><span>Use 203.0.113.4/32 for one IPv4 address or 203.0.113.0/24 for a subnet.</span></div>
        <div class="help-item"><b>Allow lists</b><span>If any allow rule exists, unmatched visitors are denied. Add all trusted networks first.</span></div>
        <div class="help-item"><b>Block lists</b><span>Block rules win over allow rules and are best for abusive sources.</span></div>
      </div>
      <div class="grid gap-4 md:grid-cols-[180px_minmax(0,1fr)_auto] md:items-end">
        <label><span class="field-label">Type</span><select v-model="bulkType" class="input"><option value="block">Block</option><option value="allow">Allow</option></select><span class="field-description">Choose one action for every CIDR in the paste box.</span></label>
        <label><span class="field-label">CIDRs</span><textarea v-model="bulkText" class="input min-h-24 py-3 font-mono" placeholder="203.0.113.0/24&#10;198.51.100.10/32" /><span class="field-description">Paste one IPv4 CIDR per line.</span></label>
        <button class="button-secondary">Import</button>
      </div>
    </form>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit IP rule' : 'Add IP rule' }}</h2><p>Block rules win over allow rules. If any allow rule exists, unmatched clients are denied.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <label><span class="field-label">Type</span><select v-model="form.rule_type" class="input"><option value="block">Block</option><option value="allow">Allow</option></select><span class="field-description">Block denies matching visitors. Allow creates a trusted list when any allow rule exists.</span></label>
        <label><span class="field-label">CIDR</span><input v-model="form.cidr" class="input" placeholder="192.0.2.0/24" /><span class="field-description">Use /32 for one IP address, such as 198.51.100.10/32.</span></label>
        <label class="md:col-span-2"><span class="field-label">Description</span><input v-model="form.description" class="input" placeholder="Office VPN or abusive scanner range" /><span class="field-description">Describe who owns the range or why it is blocked.</span></label>
      </div>
      <label class="setting-row mt-5">
        <span><b>Enabled</b><small>Turn this rule on without changing its configuration.</small></span>
        <input v-model="form.enabled" class="toggle" type="checkbox" />
      </label>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save rule' }}</button>
      </div>
    </form>

    <EmptyState v-if="!loading && rows.length === 0" title="No IP rules yet" message="Add an allow or block rule for this domain." />
    <DataTable v-else title="IP access rules" :rows="rows" :columns="columns">
      <template #enabled="{ row }"><button class="rounded-full focus:outline-none focus:ring-4 focus:ring-cyan-500/20" @click="toggle(row)"><StatusBadge :status="row.enabled ? 'healthy' : 'disabled'" :label="row.enabled ? 'Enabled' : 'Disabled'" /></button></template>
      <template #rule_type="{ value }"><StatusBadge :status="String(value) === 'allow' ? 'healthy' : 'critical'" :label="String(value)" /></template>
      <template #actions="{ row }"><div class="flex gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="startEdit(row)">Edit</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this IP rule?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
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
import { ipRulesApi } from '@/lib/api/ipRules';
import type { IpRule } from '@/types';

const props = defineProps<{ domainId: string }>();
const rows = ref<IpRule[]>([]);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const editingId = ref('');
const message = ref('');
const bulkType = ref<'allow' | 'block'>('block');
const bulkText = ref('');
const form = reactive({ enabled: true, rule_type: 'block', cidr: '', description: '' });
const columns = [
  { key: 'enabled', label: 'Enabled' }, { key: 'rule_type', label: 'Type' }, { key: 'cidr', label: 'CIDR' },
  { key: 'description', label: 'Description' }, { key: 'actions', label: 'Actions' },
];

function reset() { Object.assign(form, { enabled: true, rule_type: 'block', cidr: '', description: '' }); }
async function load() { loading.value = true; try { rows.value = await ipRulesApi.list(props.domainId); } finally { loading.value = false; } }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); Object.assign(form, { enabled: Boolean(row.enabled), rule_type: String(row.rule_type ?? 'block'), cidr: String(row.cidr ?? ''), description: String(row.description ?? '') }); editing.value = true; }
async function save() { saving.value = true; try { editingId.value ? await ipRulesApi.update(props.domainId, editingId.value, form) : await ipRulesApi.create(props.domainId, form); editing.value = false; message.value = 'IP rule saved.'; await load(); } finally { saving.value = false; } }
async function bulkImport() { const cidrs = bulkText.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean); for (const cidr of cidrs) await ipRulesApi.create(props.domainId, { enabled: true, rule_type: bulkType.value, cidr }); bulkText.value = ''; message.value = `${cidrs.length} IP rules imported.`; await load(); }
async function toggle(row: Record<string, unknown>) { await ipRulesApi.update(props.domainId, String(row.id), { enabled: !row.enabled }); await load(); }
async function remove(row: Record<string, unknown>) { await ipRulesApi.remove(props.domainId, String(row.id)); message.value = 'IP rule deleted.'; await load(); }
watch(() => props.domainId, load); onMounted(load);
</script>
