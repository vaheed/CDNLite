<template>
  <span :class="classes" class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset">{{ label ?? status }}</span>
</template>
<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ status?: string; label?: string }>();
const classes = computed(() => {
  const status = (props.status ?? '').toLowerCase();
  if (['ok', 'active', 'healthy', 'enabled', 'online', 'success'].includes(status)) return 'bg-emerald-400/10 text-emerald-200 ring-emerald-300/20';
  if (['warning', 'stale', 'pending', 'info'].includes(status)) return 'bg-amber-400/10 text-amber-200 ring-amber-300/20';
  if (['critical', 'expired', 'offline', 'failed', 'error', 'disabled'].includes(status)) return 'bg-red-400/10 text-red-200 ring-red-300/20';
  return 'bg-slate-400/10 text-slate-200 ring-slate-300/20';
});
</script>
