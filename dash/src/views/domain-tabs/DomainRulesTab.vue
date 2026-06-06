<template>
  <section class="space-y-4">
    <div class="card flex flex-wrap items-center justify-between gap-3 p-5">
      <div><h2 class="text-xl font-bold">{{ title }}</h2><p class="text-sm text-slate-500">{{ summary }}</p></div>
      <button class="button-primary" @click="startCreate">Add rule</button>
    </div>
    <div v-if="message" role="status" class="rounded-lg border border-cyan-200 bg-cyan-50 p-3 text-sm text-cyan-800">{{ message }}</div>
    <form v-if="editing" class="card grid gap-4 p-5 md:grid-cols-2" @submit.prevent="save">
      <label v-for="field in fields" :key="field.key" class="space-y-2">
        <span class="text-sm font-semibold">{{ field.label }}</span>
        <input v-if="field.type === 'checkbox'" v-model="form[field.key]" type="checkbox" />
        <textarea v-else-if="field.type === 'textarea'" v-model="form[field.key]" class="input min-h-28" />
        <select v-else-if="field.options" v-model="form[field.key]" class="input"><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
        <input v-else v-model="form[field.key]" :type="field.type === 'number' ? 'number' : 'text'" class="input" />
      </label>
      <div class="flex gap-2 md:col-span-2"><button class="button-primary" :disabled="saving">Save</button><button type="button" class="button-secondary" @click="editing = false">Cancel</button></div>
    </form>
    <EmptyState v-if="!loading && rows.length === 0" :title="`No ${title.toLowerCase()} yet`" message="Add the first rule for this domain." />
    <DataTable v-else :title="title" :rows="rows" :columns="columns">
      <template #enabled="{ row }"><button class="button-secondary px-2 py-1 text-xs" @click="toggle(row)">{{ row.enabled ? 'Disable' : 'Enable' }}</button></template>
      <template #actions="{ row }"><div class="flex gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="startEdit(row)">Edit</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this rule?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
    </DataTable>
  </section>
</template>
<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
type Field = { key: string; label: string; type?: 'text' | 'number' | 'checkbox' | 'textarea'; options?: string[]; default: string | number | boolean };
const props = defineProps<{ domainId: string; title: string; summary: string; fields: Field[]; columns: Array<{ key: string; label: string }>; list: () => Promise<unknown[]>; create: (input: Record<string, unknown>) => Promise<unknown>; update: (id: string, input: Record<string, unknown>) => Promise<unknown>; remove: (id: string) => Promise<unknown> }>();
const rows = ref<Record<string, unknown>[]>([]); const loading = ref(false); const saving = ref(false); const editing = ref(false); const editingId = ref(''); const message = ref(''); const form = reactive<Record<string, any>>({});
function reset() { props.fields.forEach((field) => { form[field.key] = field.default; }); }
async function load() { loading.value = true; try { rows.value = await props.list() as Record<string, unknown>[]; } finally { loading.value = false; } }
function payload() { return Object.fromEntries(props.fields.map((field) => { const value = form[field.key]; if (field.type === 'number') return [field.key, Number(value)]; if (field.type === 'textarea') { try { return [field.key, JSON.parse(String(value))]; } catch { return [field.key, {}]; } } return [field.key, value]; })); }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); props.fields.forEach((field) => { const value = row[field.key]; form[field.key] = field.type === 'textarea' ? JSON.stringify(value ?? {}, null, 2) : typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? value : field.default; }); editing.value = true; }
async function save() { saving.value = true; try { if (editingId.value) await props.update(editingId.value, payload()); else await props.create(payload()); editing.value = false; message.value = `${props.title} saved.`; await load(); } catch (error) { message.value = error instanceof Error ? error.message : 'Unable to save rule.'; } finally { saving.value = false; } }
async function toggle(row: Record<string, unknown>) { await props.update(String(row.id), { enabled: !row.enabled }); await load(); }
async function remove(row: Record<string, unknown>) { await props.remove(String(row.id)); message.value = `${props.title} deleted.`; await load(); }
watch(() => props.domainId, load); onMounted(() => { reset(); load(); });
</script>
