<template>
  <FieldShell v-bind="help">
    <textarea :value="modelValue" class="input min-h-32 font-mono" autocomplete="off" spellcheck="false" :placeholder="help.example" @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)" />
    <p class="mt-2 text-xs text-amber-200">Secret-safe field: value is session-memory only and is never written to localStorage by this dashboard.</p>
  </FieldShell>
</template>
<script setup lang="ts">
import FieldShell from './FieldShell.vue';
defineProps<{ modelValue: string; help: { label: string; what: string; works: string; example: string; error?: string; required?: boolean } }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
