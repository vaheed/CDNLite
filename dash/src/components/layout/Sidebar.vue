<template>
  <aside class="hidden w-72 shrink-0 border-r border-white/10 bg-slate-950/80 p-4 lg:block">
    <RouterLink to="/" class="mb-6 flex items-center gap-3 rounded-2xl bg-sky-400/10 p-3">
      <div class="grid h-10 w-10 place-items-center rounded-xl bg-sky-400 text-slate-950 font-black">CDN</div>
      <div><p class="font-bold text-white">{{ appName }}</p><p class="text-xs text-slate-400">Operations console</p></div>
    </RouterLink>
    <nav class="space-y-1">
      <RouterLink v-for="item in navItems" :key="item.to" :to="item.to" class="block rounded-xl px-3 py-2 text-sm text-slate-300 hover:bg-white/[0.06] hover:text-white" active-class="bg-sky-400/10 text-sky-200 ring-1 ring-sky-300/20">{{ item.label }}</RouterLink>
    </nav>
  </aside>
</template>
<script setup lang="ts">
import { runtimeConfig } from '@/lib/config/env';
import { navItems } from './nav';
const appName = runtimeConfig.appName;
</script>
