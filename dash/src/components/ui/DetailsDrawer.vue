<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50" @keydown.esc="$emit('close')">
      <button class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" aria-label="Close details" @click="$emit('close')" />
      <aside
        ref="panel"
        class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-950"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
      >
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
          <div><p class="text-xs font-bold uppercase tracking-wider text-cyan-700 dark:text-cyan-300">Details</p><h2 :id="titleId" class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ title }}</h2></div>
          <button class="button-secondary h-9 w-9 p-0" aria-label="Close details" @click="$emit('close')">×</button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-5"><slot /></div>
      </aside>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
const props = defineProps<{ open: boolean; title: string }>();
defineEmits<{ close: [] }>();
const panel = ref<HTMLElement | null>(null);
const titleId = `drawer-title-${Math.random().toString(36).slice(2)}`;
watch(() => props.open, async (open) => { if (open) { await nextTick(); panel.value?.focus(); } });
</script>
