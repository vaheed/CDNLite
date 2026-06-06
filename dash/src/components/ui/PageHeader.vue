<template>
  <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div class="max-w-3xl">
      <p v-if="eyebrow" class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700 dark:text-cyan-300">{{ eyebrow }}</p>
      <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950 dark:text-white sm:text-3xl">{{ title }}</h1>
      <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap gap-2"><slot name="actions" /></div>
  </header>
</template>

<script setup lang="ts">
defineProps<{ title: string; description: string; eyebrow?: string }>();
</script>
