<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="forId" class="block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
      {{ label }}
    </label>
    <slot />
    <p v-if="error" class="text-xs leading-5 text-red-600 dark:text-red-300">{{ error }}</p>
    <p v-else-if="helper" class="text-xs leading-5 text-slate-500 dark:text-slate-400">{{ helper }}</p>
  </div>
</template>

<script setup lang="ts">
defineProps<{ label?: string; helper?: string; error?: string; forId?: string }>();
</script>
