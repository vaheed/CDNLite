<template>
  <FieldShell v-bind="help">
    <input
      :value="String(modelValue ?? '')"
      class="input"
      :type="type ?? 'text'"
      :placeholder="help.example"
      :aria-label="help.label"
      :autofocus="autofocus"
      :autocomplete="autocomplete"
      :aria-invalid="Boolean(help.error)"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </FieldShell>
</template>
<script setup lang="ts">
import FieldShell from './FieldShell.vue';
defineProps<{ modelValue: string | number | boolean; type?: string; autofocus?: boolean; autocomplete?: string; help: { label: string; what: string; works: string; example: string; error?: string; required?: boolean } }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
