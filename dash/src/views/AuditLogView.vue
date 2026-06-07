<template>
  <section class="space-y-6">
    <PageHeader title="Audit Log" description="Review administrative and system changes with before and after state." eyebrow="Operations">
      <template #actions><button class="button-secondary" @click="exportCsv">Export CSV</button></template>
    </PageHeader>
    <form class="card grid gap-3 p-4 md:grid-cols-3 xl:grid-cols-4" @submit.prevent="applyFilters">
      <label><span class="field-label">Actor</span><input v-model="filters.actor" class="input" placeholder="admin or edge" /></label>
      <label><span class="field-label">Action</span><input v-model="filters.action" class="input" placeholder="domain.update" /></label>
      <label><span class="field-label">Resource type</span><input v-model="filters.resource_type" class="input" placeholder="domain" /></label>
      <label><span class="field-label">Domain</span><select v-model="filters.domain_id" class="input"><option value="">All domains</option><option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.domain }}</option></select></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label><label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
      <div class="flex items-end gap-2"><button class="button-primary flex-1">Apply</button><button type="button" class="button-secondary" @click="clear">Clear</button></div>
    </form>
    <div v-if="error" class="state-error">{{ error }}</div><LoadingSkeleton v-else-if="loading" />
    <EmptyState v-else-if="!result.items.length" title="No audit entries" message="No changes match the selected filters." />
    <div v-else class="card overflow-hidden"><HorizontalScrollFrame :watch-key="result.items.length"><table class="w-full min-w-[760px] text-left text-sm"><thead class="table-head"><tr><th>Time</th><th>Actor</th><th>Action</th><th>Resource</th><th>Domain</th><th></th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-white/5"><tr v-for="entry in result.items" :key="entry.id"><td class="table-cell whitespace-nowrap">{{ formatDate(entry.created_at) }}</td><td class="table-cell">{{ entry.actor_id || entry.actor_type }}</td><td class="table-cell font-semibold">{{ entry.action }}</td><td class="table-cell">{{ entry.resource_type }}<span v-if="entry.resource_id" class="block font-mono text-xs text-slate-500">{{ entry.resource_id }}</span></td><td class="table-cell">{{ entry.domain_name || entry.domain_id || 'Platform' }}</td><td class="table-cell"><button class="button-secondary px-3 py-1.5 text-xs" @click="selected=entry">View diff</button></td></tr></tbody>
    </table></HorizontalScrollFrame></div>
    <div class="flex items-center justify-between text-sm"><span>{{ result.total }} entries</span><div class="flex gap-2"><button class="button-secondary" :disabled="offset===0" @click="page(-1)">Previous</button><button class="button-secondary" :disabled="offset+limit>=result.total" @click="page(1)">Next</button></div></div>
    <DetailsDrawer :open="Boolean(selected)" title="Audit change" @close="selected=null"><div v-if="selected" class="space-y-4"><h3 class="font-bold">Before</h3><pre class="overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ json(selected.before) }}</pre><h3 class="font-bold">After</h3><pre class="overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ json(selected.after) }}</pre><h3 class="font-bold">Details</h3><pre class="overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ json(selected.details) }}</pre></div></DetailsDrawer>
  </section>
</template>
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'; import PageHeader from '@/components/ui/PageHeader.vue'; import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue'; import EmptyState from '@/components/ui/EmptyState.vue'; import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import { auditLogApi, type AuditFilters } from '@/lib/api/auditLog'; import { domainsApi } from '@/lib/api/domains'; import { formatDate } from '@/lib/utils/format'; import type { AuditEntry, Domain, PaginatedResult } from '@/types';
const domains=ref<Domain[]>([]),selected=ref<AuditEntry|null>(null),loading=ref(true),error=ref(''),fromInput=ref(''),toInput=ref('');const limit=50,offset=ref(0);const filters=reactive<AuditFilters>({actor:'',action:'',resource_type:'',domain_id:''});const result=ref<PaginatedResult<AuditEntry>>({items:[],total:0,limit,offset:0});
onMounted(async()=>{domains.value=await domainsApi.list();await load();});async function load(){loading.value=true;error.value='';try{result.value=await auditLogApi.list({...filters,limit,offset:offset.value});}catch(e){error.value=e instanceof Error?e.message:'Could not load audit log.';}finally{loading.value=false;}}
function applyFilters(){filters.from=toEpoch(fromInput.value);filters.to=toEpoch(toInput.value);offset.value=0;void load();}function clear(){Object.assign(filters,{actor:'',action:'',resource_type:'',domain_id:'',from:undefined,to:undefined});fromInput.value='';toInput.value='';applyFilters();}function page(direction:number){offset.value=Math.max(0,offset.value+direction*limit);void load();}function json(value:unknown){return JSON.stringify(value??{},null,2);}function toEpoch(value:string){return value?Math.floor(new Date(value).getTime()/1000):undefined;}
function exportCsv(){const fields=['created_at','actor_type','actor_id','action','resource_type','resource_id','domain_id'];const rows=[fields.join(','),...result.value.items.map(entry=>fields.map(field=>`"${String(entry[field as keyof AuditEntry]??'').replaceAll('"','""')}"`).join(','))];const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));link.download='cdnlite-audit-log.csv';link.click();URL.revokeObjectURL(link.href);}
</script>
