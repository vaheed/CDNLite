<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>{{ title }}</h2><p>{{ summary }}</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add rule</button>
    </div>
    <div v-if="message" role="status" class="notice-info">{{ message }}</div>
    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? `Edit ${singularTitle}` : `Add ${singularTitle}` }}</h2><p>Configure the match criteria and edge behavior.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div v-if="helpItems.length" class="help-panel">
        <div v-for="item in helpItems" :key="item.title" class="help-item">
          <b>{{ item.title }}</b>
          <span>{{ item.body }}</span>
        </div>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <label v-for="field in nonBooleanFields" :key="field.key">
          <span class="field-label">{{ field.label }}</span>
          <textarea v-if="field.type === 'textarea'" v-model="form[field.key]" class="input min-h-32 py-3 font-mono" :placeholder="field.placeholder" />
          <select v-else-if="field.options" v-model="form[field.key]" class="input"><option v-for="option in field.options" :key="option" :value="option">{{ humanize(option) }}</option></select>
          <input v-else v-model="form[field.key]" :type="field.type === 'number' ? 'number' : 'text'" class="input" :placeholder="field.placeholder" />
          <span v-if="field.help" class="field-description">{{ field.help }}</span>
        </label>
      </div>
      <div v-if="booleanFields.length" class="mt-5 grid gap-3 md:grid-cols-2">
        <label v-for="field in booleanFields" :key="field.key" class="setting-row">
          <span><b>{{ field.label }}</b><small>{{ field.help || 'Turn this behavior on or off without deleting the rule.' }}</small></span>
          <input v-model="form[field.key]" class="toggle" type="checkbox" />
        </label>
      </div>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save rule' }}</button>
      </div>
    </form>
    <EmptyState v-if="!loading && rows.length === 0" :title="`No ${title.toLowerCase()} yet`" message="Add the first rule for this domain." />
    <DataTable v-else :title="title" :rows="rows" :columns="columns">
      <template #enabled="{ row }"><button class="rounded-full focus:outline-none focus:ring-4 focus:ring-cyan-500/20" :aria-label="`${row.enabled ? 'Disable' : 'Enable'} rule`" @click="toggle(row)"><StatusBadge :status="row.enabled ? 'healthy' : 'disabled'" :label="row.enabled ? 'Enabled' : 'Disabled'" /></button></template>
      <template #action="{ value }"><StatusBadge :status="String(value) === 'allow' ? 'healthy' : String(value) === 'log' ? 'info' : 'critical'" :label="humanize(String(value))" /></template>
      <template #managed_by="{ row }">
        <div v-if="row.managed_by" class="flex flex-wrap gap-1">
          <StatusBadge status="info" :label="`Managed by ${humanize(String(row.managed_by))}`" />
          <StatusBadge v-if="row.user_modified" status="warning" label="Customized by user" />
        </div>
        <span v-else class="text-xs text-slate-400">Manual</span>
      </template>
      <template #actions="{ row }"><div class="flex gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="startEdit(row)">Edit</button><button v-if="row.managed_by && detachManaged" class="button-secondary px-2 py-1 text-xs" @click="detach(row)">Detach</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this rule?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
    </DataTable>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
type Field = { key: string; label: string; type?: 'text' | 'number' | 'checkbox' | 'textarea'; options?: string[]; default: string | number | boolean; placeholder?: string; help?: string };
type HelpItem = { title: string; body: string };
const props = defineProps<{ domainId: string; title: string; summary: string; fields: Field[]; columns: Array<{ key: string; label: string }>; helpItems?: HelpItem[]; list: () => Promise<unknown[]>; create: (input: Record<string, unknown>) => Promise<unknown>; update: (id: string, input: Record<string, unknown>) => Promise<unknown>; remove: (id: string) => Promise<unknown>; detachManaged?: (id: string) => Promise<unknown> }>();
const rows = ref<Record<string, unknown>[]>([]); const loading = ref(false); const saving = ref(false); const editing = ref(false); const editingId = ref(''); const message = ref(''); const form = reactive<Record<string, any>>({});
const booleanFields = computed(() => props.fields.filter((field) => field.type === 'checkbox'));
const nonBooleanFields = computed(() => props.fields.filter((field) => field.type !== 'checkbox'));
const helpItems = computed(() => props.helpItems ?? []);
const singularTitle = computed(() => props.title.replace(/ Rules$/, ' rule').replace(/s$/, ''));
function humanize(value: string) { return value.replaceAll('_', ' '); }
function reset() { props.fields.forEach((field) => { form[field.key] = field.default; }); }
async function load() { loading.value = true; try { rows.value = await props.list() as Record<string, unknown>[]; } finally { loading.value = false; } }
function payload() { return Object.fromEntries(props.fields.map((field) => { const value = form[field.key]; if (field.type === 'number') return [field.key, Number(value)]; if (field.type === 'textarea') { try { return [field.key, JSON.parse(String(value))]; } catch { return [field.key, {}]; } } return [field.key, value]; })); }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); props.fields.forEach((field) => { const value = row[field.key]; form[field.key] = field.type === 'textarea' ? JSON.stringify(value ?? {}, null, 2) : typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? value : field.default; }); editing.value = true; }
async function save() { saving.value = true; try { if (editingId.value) await props.update(editingId.value, payload()); else await props.create(payload()); editing.value = false; message.value = `${props.title} saved.`; await load(); } catch (error) { message.value = error instanceof Error ? error.message : 'Unable to save rule.'; } finally { saving.value = false; } }
async function toggle(row: Record<string, unknown>) { await props.update(String(row.id), { enabled: !row.enabled }); await load(); }
async function remove(row: Record<string, unknown>) { await props.remove(String(row.id)); message.value = `${props.title} deleted.`; await load(); }
async function detach(row: Record<string, unknown>) { if (!props.detachManaged) return; await props.detachManaged(String(row.id)); message.value = 'Rule detached from managed protection.'; await load(); }
watch(() => props.domainId, load); onMounted(() => { reset(); load(); });
</script>
