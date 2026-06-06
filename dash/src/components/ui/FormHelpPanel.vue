<template>
  <aside class="rounded-xl border border-blue-200 bg-blue-50/70 p-5 dark:border-blue-400/20 dark:bg-blue-400/5">
    <h2 class="font-bold text-slate-950 dark:text-white">{{ title }}</h2>
    <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ description }}</p>
    <details v-if="before || mistakes" class="mt-4 text-sm">
      <summary class="cursor-pointer font-semibold text-blue-700 dark:text-blue-300">Before you start and common mistakes</summary>
      <p v-if="before" class="mt-3 text-slate-600 dark:text-slate-300"><b>Before you start:</b> {{ before }}</p>
      <p v-if="mistakes" class="mt-2 text-slate-600 dark:text-slate-300"><b>Common mistakes:</b> {{ mistakes }}</p>
    </details>
  </aside>
</template>
<script setup lang="ts">
defineProps<{ title: string; description: string; before?: string; mistakes?: string }>();
</script>
