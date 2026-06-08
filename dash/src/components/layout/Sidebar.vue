<template>
  <aside class="app-panel sticky top-0 hidden h-screen w-64 shrink-0 border-y-0 border-l-0 p-4 lg:block">
    <RouterLink to="/" class="mb-7 flex items-center gap-3 rounded-xl p-2">
      <div class="grid h-10 w-10 place-items-center rounded-lg bg-slate-950 text-sm font-black text-white shadow-sm dark:bg-cyan-400 dark:text-slate-950">CDN</div>
      <div><p class="font-bold text-slate-950 dark:text-white">{{ appName }}</p><p class="text-xs text-slate-500 dark:text-slate-400">Operations console</p></div>
    </RouterLink>
    <p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Workspace</p>
    <nav class="space-y-1">
      <RouterLink v-for="item in navItems" :key="item.to" :to="item.to" class="block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100/80 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/[0.06] dark:hover:text-white" active-class="!bg-cyan-50 !text-cyan-800 dark:!bg-cyan-400/10 dark:!text-cyan-200">{{ item.label }}</RouterLink>
    </nav>
  </aside>
</template>
<script setup lang="ts">
import { runtimeConfig } from '@/lib/config/env';
import { navItems } from './nav';
const appName = runtimeConfig.appName;
</script>
