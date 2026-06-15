<template><button class="button-secondary" type="button" @click="copy">{{ copied ? 'Report copied' : 'Copy Markdown report' }}</button></template>
<script setup lang="ts">
import { ref } from 'vue'; import { createMarkdownReport } from '@/lib/reports/markdown';
const props=defineProps<{title:string;data:Record<string,unknown>}>(); const copied=ref(false);
async function copy(){await copyText(createMarkdownReport(props.title,props.data));copied.value=true;window.setTimeout(()=>copied.value=false,2000);}
async function copyText(text:string){if(navigator.clipboard?.writeText){try{await navigator.clipboard.writeText(text);return;}catch{}}const textarea=document.createElement('textarea');textarea.value=text;textarea.setAttribute('readonly','');textarea.style.position='fixed';textarea.style.left='-9999px';document.body.appendChild(textarea);textarea.select();document.execCommand('copy');document.body.removeChild(textarea);}
</script>
