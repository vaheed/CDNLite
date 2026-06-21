<template>
  <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.025]">
    <button
      type="button"
      class="flex min-h-12 w-full items-center justify-between gap-3 px-4 py-3 text-left focus:outline-none focus:ring-4 focus:ring-inset focus:ring-cyan-500/20"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-slate-950 dark:text-white">{{ title }}</span>
        <span v-if="subtitle" class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ subtitle }}</span>
      </span>
      <ChevronDown class="h-4 w-4 shrink-0 text-slate-400 transition" :class="{ 'rotate-180': open }" />
    </button>
    <div v-if="open" class="border-t border-slate-200 p-4 dark:border-white/10">
      <slot />
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps<{ title: string; subtitle?: string; defaultOpen?: boolean }>();
const open = ref(props.defaultOpen ?? false);
</script>
