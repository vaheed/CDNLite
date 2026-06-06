<template><button class="button-secondary" type="button" @click="copy">{{ copied ? 'Report copied' : 'Copy Markdown report' }}</button></template>
<script setup lang="ts">
import { ref } from 'vue'; import { createMarkdownReport } from '@/lib/reports/markdown';
const props=defineProps<{title:string;data:Record<string,unknown>}>(); const copied=ref(false);
async function copy(){await navigator.clipboard.writeText(createMarkdownReport(props.title,props.data));copied.value=true;window.setTimeout(()=>copied.value=false,2000);}
</script>
