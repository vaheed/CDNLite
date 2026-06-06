<template>
  <div class="card overflow-hidden" role="status" aria-label="Loading">
    <div v-for="row in rows" :key="row" class="flex animate-pulse gap-4 border-b border-slate-100 p-5 last:border-0 dark:border-white/5">
      <div class="h-5 w-20 rounded bg-slate-200 dark:bg-slate-700" />
      <div class="h-5 flex-1 rounded bg-slate-200 dark:bg-slate-700" />
      <div class="hidden h-5 w-32 rounded bg-slate-200 sm:block dark:bg-slate-700" />
    </div>
    <span class="sr-only">Loading content</span>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 5 });
</script>
