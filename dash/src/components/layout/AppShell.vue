<template>
  <div class="app-surface min-h-screen">
    <div class="flex min-h-screen">
      <Sidebar />
      <div class="min-w-0 flex-1">
        <TopStatusBar />
        <div class="border-b border-slate-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-950 lg:hidden">
          <label class="sr-only" for="mobile-page-nav">Page</label>
          <select id="mobile-page-nav" class="input" :value="route.path" @change="goToPage">
            <option v-for="item in navItems" :key="item.to" :value="item.to">{{ item.label }}</option>
          </select>
        </div>
        <main class="mx-auto max-w-7xl space-y-6 p-3 sm:p-4 lg:p-6">
          <RouterView />
        </main>
      </div>
    </div>
    <CommandPalette />
  </div>
</template>
<script setup lang="ts">
import Sidebar from './Sidebar.vue';
import TopStatusBar from './TopStatusBar.vue';
import CommandPalette from './CommandPalette.vue';
import { navItems } from './nav';
import { useRoute, useRouter } from 'vue-router';
const route = useRoute();
const router = useRouter();
function goToPage(event: Event) {
  const value = (event.target as HTMLSelectElement).value;
  if (value) router.push(value);
}
</script>
