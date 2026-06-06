<template>
  <div class="card flex flex-col items-center justify-center gap-3 p-10 text-center">
    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-sky-400/10 text-xl font-bold text-sky-700 dark:text-sky-300" aria-hidden="true">–</div>
    <h3 class="text-lg font-semibold text-slate-950 dark:text-white">{{ title }}</h3>
    <p class="max-w-xl text-sm text-slate-500 dark:text-slate-400">{{ message }}</p>
    <div v-if="$slots.default" class="mt-2"><slot /></div>
  </div>
</template>
<script setup lang="ts">
defineProps<{ title: string; message: string }>();
</script>
