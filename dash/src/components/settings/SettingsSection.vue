<template>
  <div class="grid gap-5 md:grid-cols-2">
    <template v-for="(definition, key) in fields" :key="key">
      <SecretSettingField
        v-if="definition.secret"
        :label="labelFor(key)"
        :description="definition.description"
        :configured="Boolean((values[key] as SecretSettingValue | undefined)?.configured)"
        @update:model-value="update(key, $event)"
      />
      <SettingField
        v-else
        :model-value="values[key]"
        :label="labelFor(key)"
        :description="definition.description"
        :type="definition.type"
        @update:model-value="update(key, $event)"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import SettingField from './SettingField.vue';
import SecretSettingField from './SecretSettingField.vue';
import type { SecretSettingValue, SettingFieldDefinition } from '@/types';
defineProps<{ fields: Record<string, SettingFieldDefinition>; values: Record<string, unknown> }>();
const emit = defineEmits<{ change: [key: string, value: unknown] }>();
const labelFor = (key: string) => key.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
const update = (key: string, value: unknown) => emit('change', key, value);
</script>
