<template>
  <div v-if="ui.commandPaletteOpen" class="fixed inset-0 z-50 bg-slate-950/70 p-4 backdrop-blur" @click.self="ui.commandPaletteOpen = false">
    <div class="mx-auto mt-20 max-w-2xl overflow-hidden rounded-2xl border border-white/10 bg-slate-900 shadow-2xl">
      <input v-model="q" class="w-full border-b border-white/10 bg-transparent px-5 py-4 text-lg outline-none" placeholder="Search commands, pages, domains…" />
      <div class="max-h-96 overflow-y-auto p-2">
        <RouterLink v-for="item in filtered" :key="item.to" :to="item.to" class="block rounded-xl px-4 py-3 text-sm text-slate-200 hover:bg-white/[0.06]" @click="ui.commandPaletteOpen = false">{{ item.label }}</RouterLink>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useUiStore } from '@/stores/ui';
import { navItems } from './nav';
const ui = useUiStore(); const q = ref('');
const filtered = computed(() => navItems.filter((item) => item.label.toLowerCase().includes(q.value.toLowerCase())));
</script>
