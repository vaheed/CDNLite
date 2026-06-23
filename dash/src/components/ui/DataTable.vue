<template>
  <div class="card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between dark:border-white/10">
      <div class="min-w-0">
        <h2 class="font-semibold tracking-tight text-slate-950 dark:text-white">{{ title }}</h2>
        <p v-if="subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
      </div>
      <label class="relative w-full lg:max-w-xs">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input v-model="search" class="input pl-9" :aria-label="`Search ${title}`" :placeholder="searchPlaceholder ?? `Search ${title.toLowerCase()}...`" />
      </label>
    </div>
    <div class="grid gap-3 p-3 md:hidden">
      <article v-for="row in pagedRows" :key="rowKey(row)" class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-white/10 dark:bg-white/[0.025]">
        <slot name="mobileCard" :row="row">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 space-y-2">
              <div v-for="column in primaryMobileColumns" :key="column.key" class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ column.label }}</p>
                <div class="mt-1 min-w-0 break-words text-sm font-medium text-slate-900 dark:text-white">
                  <slot :name="column.key" :row="row" :value="row[column.key]">{{ formatCell(row[column.key]) }}</slot>
                </div>
              </div>
            </div>
            <div v-if="actionsColumn" class="shrink-0">
              <slot name="actions" :row="row" :value="row[actionsColumn.key]">{{ formatCell(row[actionsColumn.key]) }}</slot>
            </div>
          </div>
          <details v-if="detailMobileColumns.length" class="mt-3">
            <summary class="cursor-pointer text-xs font-semibold text-cyan-700 dark:text-cyan-200">Details</summary>
            <dl class="mt-3 grid gap-3">
              <div v-for="column in detailMobileColumns" :key="column.key" class="min-w-0">
                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ column.label }}</dt>
                <dd class="mt-1 min-w-0 break-words text-sm text-slate-700 dark:text-slate-300">
                  <slot :name="column.key" :row="row" :value="row[column.key]">{{ formatCell(row[column.key]) }}</slot>
                </dd>
              </div>
            </dl>
          </details>
        </slot>
      </article>
      <div v-if="pagedRows.length === 0" class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10 dark:text-slate-400">
        No matching records found.
      </div>
    </div>
    <HorizontalScrollFrame :watch-key="[pagedRows.length, columns.length]" class="hidden md:block">
      <table :class="[tableMinWidth, compact ? 'text-xs' : 'text-sm']" class="w-full border-collapse">
        <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500 dark:bg-white/[0.025] dark:text-slate-400">
          <tr>
            <th v-for="(column, index) in columns" :key="column.key" :class="[column.class, column.align === 'right' || isActionsColumn(column) ? 'text-right' : 'text-left', stickyFirstColumn && index === 0 ? stickyHeaderClass : '', isActionsColumn(column) ? stickyActionHeaderClass : '', column.width]" class="whitespace-nowrap px-4 py-3 sm:px-5">
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
            <td v-for="(column, index) in columns" :key="column.key" :class="[column.class, column.align === 'right' || isActionsColumn(column) ? 'text-right' : 'text-left', stickyFirstColumn && index === 0 ? stickyCellClass : '', isActionsColumn(column) ? stickyActionCellClass : '', column.width, compact ? 'py-2.5' : 'py-3.5']" class="max-w-80 px-4 align-middle text-slate-700 sm:px-5 dark:text-slate-300">
              <div class="min-w-0" :title="column.truncate ? formatCell(row[column.key]) : undefined" :class="column.truncate ? 'truncate' : isActionsColumn(column) ? 'inline-flex justify-end whitespace-nowrap' : 'break-words'">
                <slot :name="column.key" :row="row" :value="row[column.key]">{{ formatCell(row[column.key]) }}</slot>
              </div>
            </td>
          </tr>
          <tr v-if="pagedRows.length === 0">
            <td :colspan="columns.length" class="px-5 py-14 text-center text-sm text-slate-500 dark:text-slate-400">No matching records found.</td>
          </tr>
        </tbody>
      </table>
    </HorizontalScrollFrame>
    <div class="border-t border-slate-200 bg-slate-50/40 px-5 py-3.5 dark:border-white/10 dark:bg-white/[0.015]">
      <PaginationControls
        :total="filteredRows.length"
        :limit="currentPageSize"
        :offset="(page - 1) * currentPageSize"
        @update:offset="page = Math.floor($event / currentPageSize) + 1"
        @update:limit="currentPageSize = $event"
      />
    </div>
  </div>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ArrowUp, ArrowUpDown, Search } from 'lucide-vue-next';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';

type Row = Record<string, unknown>;
type Column = { key: string; label: string; sortable?: boolean; align?: 'left' | 'right'; class?: string; width?: string; truncate?: boolean; mobilePriority?: 'primary' | 'detail' | 'hidden' };
const props = defineProps<{ title: string; subtitle?: string; searchPlaceholder?: string; rows: Row[]; columns: Column[]; idKey?: string; pageSize?: number; compact?: boolean; stickyFirstColumn?: boolean; minWidth?: string }>();
const search = ref('');
const sortKey = ref(props.columns[0]?.key ?? 'id');
const sortDir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const currentPageSize = ref(props.pageSize ?? 10);
const filteredRows = computed(() => {
  const q = search.value.toLowerCase();
  return props.rows.filter((row) => JSON.stringify(row).toLowerCase().includes(q)).sort((a, b) => {
    const av = String(a[sortKey.value] ?? ''); const bv = String(b[sortKey.value] ?? '');
    return sortDir.value === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
  });
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / currentPageSize.value)));
const pagedRows = computed(() => filteredRows.value.slice((page.value - 1) * currentPageSize.value, page.value * currentPageSize.value));
const tableMinWidth = computed(() => props.minWidth ?? 'min-w-[860px]');
const actionsColumn = computed(() => props.columns.find(isActionsColumn));
const mobileColumns = computed(() => props.columns.filter((column) => !isActionsColumn(column) && column.mobilePriority !== 'hidden'));
const primaryMobileColumns = computed(() => {
  const explicit = mobileColumns.value.filter((column) => column.mobilePriority === 'primary');
  return explicit.length ? explicit : mobileColumns.value.slice(0, 2);
});
const detailMobileColumns = computed(() => mobileColumns.value.filter((column) => !primaryMobileColumns.value.includes(column)));
const stickyHeaderClass = 'sticky left-0 z-10 bg-slate-50/95 shadow-[1px_0_0_rgba(148,163,184,0.18)] dark:bg-slate-950/95 dark:shadow-[1px_0_0_rgba(255,255,255,0.08)]';
const stickyCellClass = 'sticky left-0 z-[1] bg-white shadow-[1px_0_0_rgba(148,163,184,0.16)] group-hover:bg-slate-50/95 dark:bg-slate-900 dark:shadow-[1px_0_0_rgba(255,255,255,0.08)] dark:group-hover:bg-slate-900';
const stickyActionHeaderClass = 'sticky right-0 z-10 min-w-36 bg-slate-50/95 shadow-[-1px_0_0_rgba(148,163,184,0.18)] dark:bg-slate-950/95 dark:shadow-[-1px_0_0_rgba(255,255,255,0.08)]';
const stickyActionCellClass = 'sticky right-0 z-[1] min-w-36 bg-white shadow-[-1px_0_0_rgba(148,163,184,0.16)] group-hover:bg-slate-50/95 dark:bg-slate-900 dark:shadow-[-1px_0_0_rgba(255,255,255,0.08)] dark:group-hover:bg-slate-900';
watch([search, filteredRows], () => { page.value = 1; });
watch(currentPageSize, () => { page.value = 1; });
function sortBy(key: string) { sortDir.value = sortKey.value === key && sortDir.value === 'asc' ? 'desc' : 'asc'; sortKey.value = key; }
function rowKey(row: Row) { return String(row[props.idKey ?? 'id'] ?? JSON.stringify(row)); }
function formatCell(value: unknown) { return typeof value === 'object' ? JSON.stringify(value) : String(value ?? ''); }
function isActionsColumn(column: Column) { return column.key === 'actions' || column.label.toLowerCase() === 'actions'; }
</script>
