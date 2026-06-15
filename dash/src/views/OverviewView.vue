<template><section class="flex min-h-[calc(100vh-8rem)] flex-col space-y-6">
  <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-3xl font-black">Overview</h1><p class="text-slate-500">A 24-hour operational summary across the CDN.</p></div><ReportExportButton title="Overview" :data="reportData"/></div>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><div v-for="card in cards" :key="card.label" class="card p-4"><p class="text-sm text-slate-500">{{card.label}}</p><p class="mt-2 text-2xl font-black">{{card.value}}</p></div></div>
  <div class="card p-5"><h2 class="text-lg font-black">Needs attention</h2><p v-if="!warnings.length" class="mt-3 text-sm text-emerald-700">No active warnings.</p><ul v-else class="mt-3 space-y-3"><li v-for="warning in warnings" :key="warning.message" class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 p-3"><div><StatusBadge :status="warning.severity"/><span class="ml-2 text-sm">{{warning.message}}</span></div><RouterLink :to="warning.link" class="font-bold text-cyan-700">Fix</RouterLink></li></ul></div>
  <div class="mt-auto grid gap-4 xl:grid-cols-2">
    <section class="card overflow-hidden">
      <div class="border-b border-slate-200 px-5 py-4 dark:border-white/10">
        <h2 class="font-semibold tracking-tight text-slate-950 dark:text-white">Top domains by requests</h2>
        <p class="mt-1 text-sm text-slate-500">Highest request volume first.</p>
      </div>
      <div v-if="topDomainRows.length" class="divide-y divide-slate-100 dark:divide-white/[0.06]">
        <RouterLink v-for="row in topDomainRows" :key="row.domain_id" :to="`/domains/${row.domain_id}/overview`" class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50/80 dark:hover:bg-white/[0.025]">
          <span class="min-w-0"><b class="block truncate text-cyan-700">{{row.domain}}</b><small class="block truncate text-slate-500">{{row.name}}</small></span>
          <span class="text-right"><b class="block text-lg text-slate-950 dark:text-white">{{row.requests}}</b><small class="text-slate-500">requests</small></span>
        </RouterLink>
      </div>
      <p v-else class="px-5 py-14 text-center text-sm text-slate-500">No request traffic recorded yet.</p>
    </section>
    <DataTable title="Recent config snapshots" :rows="snapshotRows" :columns="snapshotColumns"/>
  </div>
</section></template>
<script setup lang="ts">
import{computed,onMounted,ref}from'vue';import{RouterLink}from'vue-router';import DataTable from'@/components/ui/DataTable.vue';import StatusBadge from'@/components/ui/StatusBadge.vue';import ReportExportButton from'@/components/reports/ReportExportButton.vue';import{overviewApi}from'@/lib/api/overview';import{formatBytes,formatDate,formatPercent}from'@/lib/utils/format';import type{Overview,OverviewWarning}from'@/types';
const overview=ref<Overview|null>(null),warnings=ref<OverviewWarning[]>([]);
const cards=computed(()=>[{label:'Domains',value:overview.value?.domains_count??0},{label:'Active domains',value:overview.value?.active_domains??0},{label:'Requests (24h)',value:overview.value?.total_requests_24h??0},{label:'Bandwidth (24h)',value:formatBytes(overview.value?.bandwidth_24h_bytes??0)},{label:'Cache hit ratio',value:formatPercent(overview.value?.cache_hit_ratio_24h??0)},{label:'Edges online',value:overview.value?.edge_online??0},{label:'Edges offline',value:overview.value?.edge_offline??0},{label:'Security events',value:overview.value?.security_events_24h??0},{label:'SSL expiring',value:overview.value?.ssl_expiring_count??0}]);
const reportData=computed(()=>({metrics:overview.value,warnings:warnings.value})),snapshotRows=computed(()=>(overview.value?.recent_snapshots??[]).map(r=>({...r,generated:formatDate(r.generated_at)}))),topDomainRows=computed(()=>(overview.value?.top_domains??[]).slice().sort((a,b)=>b.requests-a.requests));const snapshotColumns=[{key:'version',label:'Version'},{key:'generated',label:'Generated'}];
onMounted(async()=>{[overview.value,warnings.value]=await Promise.all([overviewApi.get(),overviewApi.warnings()]);});
</script>
