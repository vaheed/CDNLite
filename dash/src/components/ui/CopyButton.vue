<template>
  <button class="button-secondary px-2 py-1 text-xs" @click="copy">{{ copied ? 'Copied' : label ?? 'Copy' }}</button>
</template>
<script setup lang="ts">
import { ref } from 'vue';
const props = defineProps<{ text: string; label?: string }>();
const copied = ref(false);
async function copy() {
  await navigator.clipboard.writeText(props.text);
  copied.value = true;
  window.setTimeout(() => (copied.value = false), 1200);
}
</script>
