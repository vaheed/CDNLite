<template><section class="space-y-6">
  <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-3xl font-black">Edge Nodes</h1><p class="text-slate-500">Identity, heartbeat freshness, region, version, and online/offline health.</p></div><ReportExportButton title="Edge nodes" :data="{ edges: rows }"/></div>
  <DataTable title="Edge health table" :rows="rows" :columns="columns"><template #identity="{row}"><div class="flex items-center gap-2"><span class="font-mono">{{row.edge_id}}</span><StatusBadge v-if="row.identity_status==='warning'" status="warning" label="Suspicious"/></div></template><template #health="{row}"><StatusBadge :status="String(row.health)"/></template></DataTable>
  <ChartCard title="Online / Offline" :option="chart"/>
</section></template>
<script setup lang="ts">
import{computed,onMounted,ref}from'vue';import DataTable from'@/components/ui/DataTable.vue';import ChartCard from'@/components/ui/ChartCard.vue';import StatusBadge from'@/components/ui/StatusBadge.vue';import ReportExportButton from'@/components/reports/ReportExportButton.vue';import{edgesApi}from'@/lib/api/edges';import{heartbeatStatus}from'@/lib/utils/diagnostics';import{formatDate}from'@/lib/utils/format';import type{EdgeNode}from'@/types';
const edges=ref<EdgeNode[]>([]),rows=computed(()=>edges.value.map(e=>({...e,health:heartbeatStatus(e),heartbeat:formatDate(e.last_heartbeat_at??e.last_heartbeat)})));
const columns=[{key:'identity',label:'Identity'},{key:'hostname',label:'Hostname'},{key:'public_ip',label:'Public IP'},{key:'region',label:'Region'},{key:'version',label:'Version'},{key:'heartbeat',label:'Heartbeat'},{key:'health',label:'Health'}];
const chart=computed(()=>({tooltip:{},series:[{type:'pie',data:['ok','warning','critical'].map(name=>({name,value:rows.value.filter(r=>r.health===name).length}))}]}));
onMounted(async()=>{edges.value=await edgesApi.list().catch(()=>[]);});
</script>
