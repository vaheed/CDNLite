<template>
  <label
    class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border p-3 text-left transition focus-within:ring-4 focus-within:ring-cyan-500/20"
    :class="modelValue ? activeClass : inactiveClass"
  >
    <input
      type="checkbox"
      class="peer sr-only"
      :checked="Boolean(modelValue)"
      :disabled="disabled"
      @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
    />
    <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-md border transition" :class="modelValue ? 'border-cyan-600 bg-cyan-600 text-white dark:border-cyan-300 dark:bg-cyan-300 dark:text-slate-950' : 'border-slate-300 bg-white text-transparent dark:border-white/20 dark:bg-slate-950'">
      <Check class="h-3.5 w-3.5" />
    </span>
    <span class="min-w-0">
      <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ label }}</span>
      <span v-if="helper" class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">{{ helper }}</span>
    </span>
  </label>
</template>

<script setup lang="ts">
import { Check } from 'lucide-vue-next';

defineProps<{ modelValue?: boolean; label: string; helper?: string; disabled?: boolean }>();
defineEmits<{ 'update:modelValue': [value: boolean] }>();

const activeClass = 'border-cyan-200 bg-cyan-50/80 dark:border-cyan-300/20 dark:bg-cyan-400/10';
const inactiveClass = 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.025] dark:hover:bg-white/[0.05]';
</script>
