<template>
  <FieldShell v-bind="help">
    <textarea :value="String(modelValue ?? '')" class="input min-h-32 font-mono" :placeholder="help.example" @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)" />
  </FieldShell>
</template>
<script setup lang="ts">
import FieldShell from './FieldShell.vue';
defineProps<{ modelValue: string | number | boolean; help: { label: string; what: string; works: string; example: string; error?: string; required?: boolean } }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
