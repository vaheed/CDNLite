<template>
  <div v-if="visible" class="fixed bottom-4 right-4 z-40 rounded-lg border border-cyan-200 bg-white px-4 py-3 text-sm font-medium text-cyan-900 shadow-lg dark:border-cyan-400/30 dark:bg-slate-950 dark:text-cyan-100">
    Publishing config to edge...
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { CONFIG_PUBLISHING_EVENT } from '@/lib/data/invalidation';

const visible = ref(false);
let timer: number | undefined;

function show() {
  visible.value = true;
  window.clearTimeout(timer);
  timer = window.setTimeout(() => { visible.value = false; }, 4500);
}

onMounted(() => window.addEventListener(CONFIG_PUBLISHING_EVENT, show));
onBeforeUnmount(() => {
  window.removeEventListener(CONFIG_PUBLISHING_EVENT, show);
  window.clearTimeout(timer);
});
</script>
