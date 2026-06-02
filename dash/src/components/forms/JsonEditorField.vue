<template>
  <FieldShell v-bind="help" :error="error">
    <textarea v-model="draft" class="input min-h-40 font-mono" spellcheck="false" @blur="commit" />
  </FieldShell>
</template>
<script setup lang="ts">
import { ref, watch } from 'vue';
import FieldShell from './FieldShell.vue';
const props = defineProps<{ modelValue: string; help: { label: string; what: string; works: string; example: string; error?: string; required?: boolean } }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const draft = ref(props.modelValue);
const error = ref('');
watch(() => props.modelValue, (value) => { draft.value = value; });
function commit() {
  try {
    JSON.parse(draft.value || '{}');
    error.value = '';
    emit('update:modelValue', draft.value);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Invalid JSON';
  }
}
</script>
