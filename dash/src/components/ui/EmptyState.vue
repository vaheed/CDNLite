<template>
  <div class="card flex flex-col items-center justify-center gap-3 p-10 text-center">
    <div class="rounded-2xl bg-sky-400/10 px-4 py-3 text-2xl">⌁</div>
    <h3 class="text-lg font-semibold text-slate-950 dark:text-white">{{ title }}</h3>
    <p class="max-w-xl text-sm text-slate-500 dark:text-slate-400">{{ message }}</p>
  </div>
</template>
<script setup lang="ts">
defineProps<{ title: string; message: string }>();
</script>
