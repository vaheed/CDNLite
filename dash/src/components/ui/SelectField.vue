<template>
  <FormField :label="label" :helper="helper" :error="error" :for-id="id">
    <div class="relative">
      <select
        :id="id"
        :value="modelValue ?? ''"
        :disabled="disabled"
        class="input h-11 appearance-none pr-10"
        @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <slot />
      </select>
      <ChevronDown class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
    </div>
  </FormField>
</template>

<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import FormField from '@/components/ui/FormField.vue';

defineProps<{ id: string; label: string; modelValue?: string; helper?: string; error?: string; disabled?: boolean }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
