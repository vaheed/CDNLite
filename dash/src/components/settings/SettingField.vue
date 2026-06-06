<template>
  <label class="block space-y-2">
    <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ label }}</span>
    <span v-if="description" class="block text-xs text-slate-500 dark:text-slate-400">{{ description }}</span>
    <input v-if="type === 'bool'" type="checkbox" class="h-5 w-5" :checked="Boolean(modelValue)" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)" />
    <input v-else :type="type === 'int' ? 'number' : 'text'" class="input w-full" :value="displayValue" @input="onInput" />
  </label>
</template>

<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ modelValue: unknown; label: string; description?: string | null; type: 'string' | 'bool' | 'int' | 'list' }>();
const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();
const displayValue = computed(() => Array.isArray(props.modelValue) ? props.modelValue.join(', ') : String(props.modelValue ?? ''));
function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value;
  emit('update:modelValue', props.type === 'int' ? Number(value) : props.type === 'list' ? value.split(',').map((item) => item.trim()).filter(Boolean) : value);
}
</script>
