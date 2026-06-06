<template><AnalyticsDashboard :domain-id="domainId" /></template>
<script setup lang="ts">import AnalyticsDashboard from '@/components/analytics/AnalyticsDashboard.vue'; defineProps<{domainId:string}>();</script>
