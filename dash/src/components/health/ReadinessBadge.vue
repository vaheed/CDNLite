<template>
  <button type="button" class="inline-flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-slate-100 dark:hover:bg-white/[0.06]" @click="$emit('click')">
    <StatusBadge :status="status" :label="`${label} ${status === 'ok' ? 'Ready' : status}`" />
  </button>
</template>

<script setup lang="ts">
import type { ReadinessStatus } from '@/types';
import StatusBadge from '@/components/ui/StatusBadge.vue';

defineProps<{ label: string; status: ReadinessStatus }>();
defineEmits<{ click: [] }>();
</script>
