<template>
  <button class="button-danger" type="button" :disabled="disabled" @click="onClick"><slot>Delete</slot></button>
</template>
<script setup lang="ts">
const props = defineProps<{ confirmText?: string; disabled?: boolean }>();
const emit = defineEmits<{ confirm: [] }>();
function onClick() {
  if (window.confirm(props.confirmText ?? 'This action is destructive. Continue?')) emit('confirm');
}
</script>
