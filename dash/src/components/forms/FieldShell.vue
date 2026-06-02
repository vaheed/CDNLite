<template>
  <label class="block space-y-2">
    <div class="flex items-center justify-between gap-3">
      <span class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        {{ label }}
        <span v-if="required" class="text-red-600 dark:text-red-400" aria-label="required">*</span>
        <span class="group relative inline-flex">
          <button type="button" tabindex="-1" class="grid h-5 w-5 place-items-center rounded-full border border-slate-300 text-xs font-bold text-slate-500 hover:border-cyan-500 hover:text-cyan-600 dark:border-slate-600 dark:text-slate-400 dark:hover:text-cyan-300" :aria-label="`${label} help`">?</button>
          <span class="field-help">
            <span class="block font-semibold text-slate-900 dark:text-slate-100">{{ what }}</span>
            <span class="mt-1 block">{{ works }}</span>
            <span class="mt-2 block">Example: <code class="font-mono text-cyan-700 dark:text-cyan-300">{{ example }}</code></span>
          </span>
        </span>
      </span>
      <span v-if="!required" class="text-[11px] font-medium text-slate-500 dark:text-slate-400">Optional</span>
    </div>
    <slot />
    <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">{{ what }} Example: {{ example }}</p>
    <p v-if="error" role="alert" class="text-xs font-semibold text-red-600 dark:text-red-300">{{ error }}</p>
  </label>
</template>

<script setup lang="ts">
defineProps<{ label: string; what: string; works: string; example: string; error?: string; required?: boolean }>();
</script>
