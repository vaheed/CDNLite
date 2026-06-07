<template>
  <span :class="classes" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset">
    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden="true" />
    {{ label ?? humanizedStatus }}
  </span>
</template>
<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ status?: string; label?: string }>();
const humanizedStatus = computed(() => (props.status ?? '').replaceAll('_', ' '));
const classes = computed(() => {
  const status = (props.status ?? '').toLowerCase();
  if (['ok', 'active', 'healthy', 'enabled', 'online', 'success'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-300/20';
  if (['warning', 'stale', 'pending', 'pending_nameserver'].includes(status)) return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-300/20';
  if (['info'].includes(status)) return 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-400/10 dark:text-blue-200 dark:ring-blue-300/20';
  if (['critical', 'expired', 'offline', 'failed', 'error', 'disabled'].includes(status)) return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-400/10 dark:text-red-200 dark:ring-red-300/20';
  return 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-400/10 dark:text-slate-200 dark:ring-slate-300/20';
});
</script>
