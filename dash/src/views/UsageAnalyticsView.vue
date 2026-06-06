<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">Usage Analytics</h1>
      <p class="text-slate-600 dark:text-slate-400">Requests, bandwidth, and cache effectiveness across all domains or one selected domain.</p>
      </div>
      <ReportExportButton title="Usage analytics" :data="{ page: 'Usage Analytics' }" />
    </div>
    <AnalyticsDashboard show-domain-selector />
  </section>
</template>

<script setup lang="ts">
import AnalyticsDashboard from '@/components/analytics/AnalyticsDashboard.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
</script>
