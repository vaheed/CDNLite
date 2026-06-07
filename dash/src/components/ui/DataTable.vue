<template>
  <div class="card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
      <div>
        <h2 class="font-semibold tracking-tight text-slate-950 dark:text-white">{{ title }}</h2>
        <p v-if="subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
      </div>
      <label class="relative w-full sm:max-w-xs">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input v-model="search" class="input pl-9" :aria-label="`Search ${title}`" :placeholder="searchPlaceholder ?? `Search ${title.toLowerCase()}...`" />
      </label>
    </div>
    <HorizontalScrollFrame :watch-key="[pagedRows.length, columns.length]">
      <table class="w-full min-w-[860px] border-collapse text-sm">
        <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500 dark:bg-white/[0.025] dark:text-slate-400">
          <tr>
            <th v-for="column in columns" :key="column.key" :class="[column.class, column.align === 'right' ? 'text-right' : 'text-left']" class="whitespace-nowrap px-5 py-3.5">
              <button v-if="column.sortable !== false" type="button" class="group inline-flex items-center gap-1.5 rounded focus:outline-none focus:ring-4 focus:ring-cyan-500/20" @click="sortBy(column.key)">
                {{ column.label }}
                <ArrowUpDown v-if="sortKey !== column.key" class="h-3 w-3 opacity-0 transition group-hover:opacity-60" />
                <ArrowUp v-else :class="{ 'rotate-180': sortDir === 'desc' }" class="h-3 w-3 transition" />
              </button>
              <span v-else>{{ column.label }}</span>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-white/[0.06]">
          <tr v-for="row in pagedRows" :key="rowKey(row)" class="group transition-colors hover:bg-slate-50/80 dark:hover:bg-white/[0.025]">
            <td v-for="column in columns" :key="column.key" :class="[column.class, column.align === 'right' ? 'text-right' : 'text-left']" class="max-w-80 px-5 py-4 text-slate-700 dark:text-slate-300">
              <slot :name="column.key" :row="row" :value="row[column.key]">{{ formatCell(row[column.key]) }}</slot>
            </td>
          </tr>
          <tr v-if="pagedRows.length === 0">
            <td :colspan="columns.length" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">No matching records found.</td>
          </tr>
        </tbody>
      </table>
    </HorizontalScrollFrame>
    <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/40 px-5 py-3.5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/[0.015] dark:text-slate-400">
      <span>{{ filteredRows.length }} {{ filteredRows.length === 1 ? 'record' : 'records' }}</span>
      <div class="flex items-center gap-2">
        <button class="button-secondary h-8 px-3 text-xs" :disabled="page === 1" @click="page--">Previous</button>
        <span class="min-w-20 text-center">Page {{ page }} of {{ pageCount }}</span>
        <button class="button-secondary h-8 px-3 text-xs" :disabled="page === pageCount" @click="page++">Next</button>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ArrowUp, ArrowUpDown, Search } from 'lucide-vue-next';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';

type Row = Record<string, unknown>;
type Column = { key: string; label: string; sortable?: boolean; align?: 'left' | 'right'; class?: string };
const props = defineProps<{ title: string; subtitle?: string; searchPlaceholder?: string; rows: Row[]; columns: Column[]; idKey?: string; pageSize?: number }>();
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
