<template>
  <div class="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
    <span class="text-slate-500">
      {{ total === 0 ? 'No results' : `${start}-${end} of ${total}` }}
    </span>
    <div class="flex flex-wrap items-center gap-2">
      <label v-if="showPageSize" class="flex items-center gap-2 text-xs text-slate-500">
        Rows
        <select class="input h-9 w-20 py-1" :value="limit" @change="changeLimit">
          <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
        </select>
      </label>
      <button class="button-secondary h-9 px-3 text-xs" :disabled="offset === 0" @click="$emit('update:offset', previousOffset)">Previous</button>
      <span class="min-w-24 text-center text-xs text-slate-500">Page {{ page }} of {{ pageCount }}</span>
      <button class="button-secondary h-9 px-3 text-xs" :disabled="offset + limit >= total" @click="$emit('update:offset', offset + limit)">Next</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
  total: number;
  limit: number;
  offset: number;
  showPageSize?: boolean;
  pageSizes?: number[];
}>(), {
  showPageSize: true,
  pageSizes: () => [10, 25, 50, 100],
});

const emit = defineEmits<{
  'update:offset': [value: number];
  'update:limit': [value: number];
}>();

const page = computed(() => Math.floor(props.offset / props.limit) + 1);
const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.limit)));
const start = computed(() => props.total === 0 ? 0 : props.offset + 1);
const end = computed(() => Math.min(props.total, props.offset + props.limit));
const previousOffset = computed(() => Math.max(0, props.offset - props.limit));

function changeLimit(event: Event) {
  emit('update:limit', Number((event.target as HTMLSelectElement).value));
  emit('update:offset', 0);
}
</script>
