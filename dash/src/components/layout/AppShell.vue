<template>
  <div class="app-surface min-h-screen">
    <div class="flex min-h-screen">
      <Sidebar />
      <div class="min-w-0 flex-1">
        <TopStatusBar />
        <div class="border-b border-slate-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-950 lg:hidden">
          <button class="button-secondary w-full justify-between" aria-controls="mobile-navigation" :aria-expanded="mobileOpen" @click="mobileOpen = true"><span>Navigate</span><span>{{ currentPage }}</span></button>
        </div>
        <main class="mx-auto max-w-7xl space-y-6 p-3 sm:p-4 lg:p-6">
          <RouterView />
        </main>
      </div>
    </div>
    <div v-if="mobileOpen" class="fixed inset-0 z-40 lg:hidden">
      <button class="absolute inset-0 bg-slate-950/50" aria-label="Close navigation" @click="mobileOpen = false" />
      <aside id="mobile-navigation" class="absolute inset-y-0 left-0 w-[min(85vw,20rem)] border-r border-slate-200 bg-white p-4 shadow-2xl dark:border-white/10 dark:bg-slate-950">
        <div class="mb-5 flex items-center justify-between"><b>CDNLite navigation</b><button class="button-secondary h-9 w-9 p-0" aria-label="Close navigation" @click="mobileOpen = false">×</button></div>
        <nav class="space-y-1"><RouterLink v-for="item in navItems" :key="item.to" :to="item.to" class="block rounded-lg px-3 py-3 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/[0.06]" active-class="!bg-cyan-50 !text-cyan-800 dark:!bg-cyan-400/10 dark:!text-cyan-200" @click="mobileOpen = false">{{ item.label }}</RouterLink></nav>
      </aside>
    </div>
    <CommandPalette />
  </div>
</template>
<script setup lang="ts">
import Sidebar from './Sidebar.vue';
import TopStatusBar from './TopStatusBar.vue';
import CommandPalette from './CommandPalette.vue';
import { navItems } from './nav';
import { useRoute } from 'vue-router';
import { computed, ref } from 'vue';
const route = useRoute();
const mobileOpen = ref(false);
const currentPage = computed(() => navItems.find((item) => item.to === route.path)?.label ?? 'Current page');
</script>
