<template>
  <section class="space-y-6">
    <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">Usage Analytics</h1>
      <p class="text-slate-600 dark:text-slate-400">Requests, bandwidth, and cache effectiveness across all domains or one selected domain.</p>
    </div>
    <AnalyticsDashboard show-domain-selector />
  </section>
</template>

<script setup lang="ts">
import AnalyticsDashboard from '@/components/analytics/AnalyticsDashboard.vue';
</script>
