<template>
  <div class="card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
      <div>
        <h3 class="font-semibold text-slate-950 dark:text-white">{{ title }}</h3>
        <p v-if="subtitle" class="text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
      </div>
      <input v-model="search" class="input sm:max-w-xs" placeholder="Search table…" />
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-[760px] divide-y divide-slate-200 text-sm dark:divide-white/10">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
          <tr><th v-for="column in columns" :key="column.key" class="px-4 py-3 cursor-pointer" @click="sortBy(column.key)">{{ column.label }} <span v-if="sortKey === column.key">{{ sortDir === 'asc' ? '↑' : '↓' }}</span></th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
          <tr v-for="row in pagedRows" :key="rowKey(row)" class="hover:bg-slate-50 dark:hover:bg-white/[0.03]">
            <td v-for="column in columns" :key="column.key" class="max-w-72 break-words px-4 py-3 text-slate-700 dark:text-slate-300">
              <slot :name="column.key" :row="row" :value="row[column.key]">{{ formatCell(row[column.key]) }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="flex flex-col gap-3 border-t border-slate-200 p-3 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:text-slate-400">
      <span>{{ filteredRows.length }} records</span>
      <div class="flex items-center gap-2">
        <button class="button-secondary px-2 py-1 text-xs" :disabled="page === 1" @click="page--">Prev</button>
        <span>Page {{ page }} / {{ pageCount }}</span>
        <button class="button-secondary px-2 py-1 text-xs" :disabled="page === pageCount" @click="page++">Next</button>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';

type Row = Record<string, unknown>;
const props = defineProps<{ title: string; subtitle?: string; rows: Row[]; columns: Array<{ key: string; label: string }>; idKey?: string; pageSize?: number }>();
const search = ref('');
const sortKey = ref(props.columns[0]?.key ?? 'id');
const sortDir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const pageSize = computed(() => props.pageSize ?? 10);
const filteredRows = computed(() => {
  const q = search.value.toLowerCase();
  return props.rows.filter((row) => JSON.stringify(row).toLowerCase().includes(q)).sort((a, b) => {
    const av = String(a[sortKey.value] ?? ''); const bv = String(b[sortKey.value] ?? '');
    return sortDir.value === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
  });
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value)));
const pagedRows = computed(() => filteredRows.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
watch([search, filteredRows], () => { page.value = 1; });
function sortBy(key: string) { sortDir.value = sortKey.value === key && sortDir.value === 'asc' ? 'desc' : 'asc'; sortKey.value = key; }
function rowKey(row: Row) { return String(row[props.idKey ?? 'id'] ?? JSON.stringify(row)); }
function formatCell(value: unknown) { return typeof value === 'object' ? JSON.stringify(value) : String(value ?? ''); }
</script>
