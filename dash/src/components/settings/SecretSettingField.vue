<template>
  <div class="space-y-2">
    <div class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ label }}</div>
    <div class="text-xs text-slate-500 dark:text-slate-400">{{ description }}</div>
    <div v-if="!editing" class="flex items-center gap-3">
      <span class="font-mono text-sm">{{ configured ? '••••• (configured)' : 'Not configured' }}</span>
      <button class="button-secondary" type="button" @click="editing = true">Update</button>
    </div>
    <input v-else class="input w-full" type="password" autocomplete="new-password" placeholder="Enter new secret" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
defineProps<{ configured: boolean; label: string; description?: string | null; modelValue?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const editing = ref(false);
</script>
