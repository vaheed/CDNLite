<template>
  <button type="button" class="inline-flex items-center rounded-full transition hover:brightness-95 focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:hover:brightness-110" @click="$emit('click')">
    <StatusBadge :status="status" :label="`${label} ${status === 'ok' ? 'Ready' : status === 'unknown' ? 'Unknown' : status}`" />
  </button>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue';

defineProps<{ label: string; status: 'ok' | 'warning' | 'error' | 'unknown' }>();
defineEmits<{ click: [] }>();
</script>
