<template>
  <section class="space-y-6">
    <PageHeader title="Security Events" description="Search edge security decisions across every domain." eyebrow="Security">
      <template #actions><button class="button-secondary" :disabled="loading" @click="load">Refresh</button></template>
    </PageHeader>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="card p-5"><p class="text-sm text-slate-500">Total events</p><p class="mt-2 text-3xl font-black">{{ summary?.total ?? 0 }}</p></div>
      <div v-for="(count, type) in summary?.by_type" :key="type" class="card p-5"><p class="text-sm text-slate-500">{{ type }}</p><p class="mt-2 text-3xl font-black">{{ count }}</p></div>
    </div>
    <form class="card grid gap-3 p-4 md:grid-cols-3 xl:grid-cols-4" @submit.prevent="applyFilters">
      <label><span class="field-label">Domain</span><select v-model="filters.domain_id" class="input"><option value="">All domains</option><option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.domain }}</option></select></label>
      <label><span class="field-label">Edge</span><input v-model="filters.edge_id" class="input" placeholder="edge-01" /></label>
      <label><span class="field-label">Event type</span><select v-model="filters.type" class="input"><option value="">All types</option><option value="waf_match">WAF match</option><option value="rate_limited">Rate limited</option><option value="geo_block">Geo block</option></select></label>
      <label><span class="field-label">IP prefix</span><input v-model="filters.ip" class="input" placeholder="203.0.113" /></label>
      <label><span class="field-label">Path / payload</span><input v-model="filters.search" class="input" type="search" placeholder="/admin" /></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
      <div class="flex items-end gap-2"><button class="button-primary flex-1">Apply</button><button type="button" class="button-secondary" @click="clear">Clear</button></div>
    </form>
    <div v-if="error" class="state-error">{{ error }}</div>
    <LoadingSkeleton v-else-if="loading" />
    <EmptyState v-else-if="!result.items.length" title="No security events" message="No events match the selected filters." />
    <div v-else class="card overflow-hidden">
      <HorizontalScrollFrame :watch-key="result.items.length">
      <table class="w-full text-left text-sm">
        <thead class="table-head"><tr><th>Time</th><th>Domain</th><th>Edge</th><th>Type</th><th>IP</th><th>Path</th><th>Action</th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-white/5"><tr v-for="event in result.items" :key="event.id">
          <td class="table-cell whitespace-nowrap">{{ formatDate(event.created_at) }}</td><td class="table-cell">{{ event.domain_name || event.domain_id || 'Unknown' }}</td>
          <td class="table-cell">{{ event.actor_id || event.edge_id || 'Unknown' }}</td><td class="table-cell font-semibold">{{ event.type }}</td>
          <td class="table-cell font-mono text-xs">{{ detail(event, 'ip') }}</td><td class="table-cell">{{ detail(event, 'path') }}</td><td class="table-cell">{{ detail(event, 'decision') || event.action || 'observed' }}</td>
        </tr></tbody>
      </table>
      </HorizontalScrollFrame>
    </div>
    <div class="flex items-center justify-between text-sm"><span>{{ result.total }} events</span><div class="flex gap-2"><button class="button-secondary" :disabled="offset === 0" @click="page(-1)">Previous</button><button class="button-secondary" :disabled="offset + limit >= result.total" @click="page(1)">Next</button></div></div>
  </section>
</template>
<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue'; import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue'; import EmptyState from '@/components/ui/EmptyState.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import { domainsApi } from '@/lib/api/domains'; import { securityEventsApi, type SecurityEventFilters } from '@/lib/api/securityEvents'; import { formatDate } from '@/lib/utils/format';
import type { Domain, PaginatedResult, SecurityEvent, SecuritySummary } from '@/types';
const domains=ref<Domain[]>([]), summary=ref<SecuritySummary|null>(null), loading=ref(true), error=ref(''), fromInput=ref(''), toInput=ref(''); const limit=50; const offset=ref(0);
const filters=reactive<SecurityEventFilters>({domain_id:'',edge_id:'',type:'',ip:'',search:''}); const result=ref<PaginatedResult<SecurityEvent>>({items:[],total:0,limit,offset:0});
onMounted(async()=>{ domains.value=await domainsApi.list(); await load(); });
async function load(){loading.value=true;error.value='';try{[result.value,summary.value]=await Promise.all([securityEventsApi.list({...filters,limit,offset:offset.value}),securityEventsApi.summary()]);}catch(e){error.value=e instanceof Error?e.message:'Could not load security events.';}finally{loading.value=false;}}
function applyFilters(){filters.from=toEpoch(fromInput.value);filters.to=toEpoch(toInput.value);offset.value=0;void load();} function clear(){Object.assign(filters,{domain_id:'',edge_id:'',type:'',ip:'',search:'',from:undefined,to:undefined});fromInput.value='';toInput.value='';applyFilters();} function page(direction:number){offset.value=Math.max(0,offset.value+direction*limit);void load();}
function detail(event:SecurityEvent,key:string){const value=event.details?.[key];return typeof value==='string'||typeof value==='number'?String(value):'';}
function toEpoch(value:string){return value?Math.floor(new Date(value).getTime()/1000):undefined;}
</script>
