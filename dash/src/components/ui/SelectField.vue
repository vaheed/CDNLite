<template>
  <FormField :label="label" :helper="helper" :error="error" :for-id="id">
    <div class="relative">
      <select
        :id="id"
        :value="modelValue ?? ''"
        :disabled="disabled"
        class="input appearance-none pr-10"
        :class="compact ? 'h-9 text-sm' : 'h-11'"
        @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <slot />
      </select>
      <ChevronDown class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
    </div>
  </FormField>
</template>

<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import FormField from '@/components/ui/FormField.vue';

defineProps<{ id: string; label: string; modelValue?: string; helper?: string; error?: string; disabled?: boolean; compact?: boolean }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
