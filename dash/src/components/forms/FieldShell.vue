<template>
  <label class="block space-y-2">
    <div class="flex items-center justify-between gap-3">
      <span class="text-sm font-semibold text-slate-100">{{ label }}</span>
      <span v-if="required" class="rounded-full bg-sky-400/10 px-2 py-0.5 text-[11px] font-medium text-sky-200">required</span>
    </div>
    <slot />
    <div class="grid gap-2 rounded-xl border border-white/10 bg-slate-950/50 p-3 text-xs text-slate-400">
      <p><span class="font-semibold text-slate-300">What this is:</span> {{ what }}</p>
      <p><span class="font-semibold text-slate-300">How this works:</span> {{ works }}</p>
      <p><span class="font-semibold text-slate-300">Example:</span> <code class="font-mono text-sky-200">{{ example }}</code></p>
      <p v-if="error" class="font-semibold text-red-300">{{ error }}</p>
    </div>
  </label>
</template>

<script setup lang="ts">
defineProps<{ label: string; what: string; works: string; example: string; error?: string; required?: boolean }>();
</script>
