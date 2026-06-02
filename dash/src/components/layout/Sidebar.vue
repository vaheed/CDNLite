<template>
  <aside class="app-panel hidden w-72 shrink-0 border-y-0 border-l-0 p-4 lg:block">
    <RouterLink to="/" class="mb-6 flex items-center gap-3 rounded-lg bg-cyan-50 p-3 dark:bg-cyan-400/10">
      <div class="grid h-10 w-10 place-items-center rounded-md bg-cyan-600 text-sm font-black text-white dark:bg-cyan-400 dark:text-slate-950">CDN</div>
      <div><p class="font-bold text-slate-950 dark:text-white">{{ appName }}</p><p class="text-xs text-slate-500 dark:text-slate-400">Operations console</p></div>
    </RouterLink>
    <nav class="space-y-1">
      <RouterLink v-for="item in navItems" :key="item.to" :to="item.to" class="block rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/[0.06] dark:hover:text-white" active-class="!bg-cyan-50 !text-cyan-800 ring-1 ring-cyan-200 dark:!bg-cyan-400/10 dark:!text-cyan-200 dark:ring-cyan-300/20">{{ item.label }}</RouterLink>
    </nav>
  </aside>
</template>
<script setup lang="ts">
import { runtimeConfig } from '@/lib/config/env';
import { navItems } from './nav';
const appName = runtimeConfig.appName;
</script>
