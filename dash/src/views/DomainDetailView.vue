<template>
  <section v-if="domain" class="space-y-6">
    <div><RouterLink to="/domains" class="text-sm text-cyan-700">Domains</RouterLink><h1 class="text-3xl font-black">{{ domain.name }}</h1><p class="text-slate-500">{{ domain.domain }}</p></div>
    <nav class="flex gap-2 overflow-x-auto border-b border-slate-200 pb-2">
      <RouterLink v-for="tab in tabs" :key="tab.key" :to="`/domains/${domainId}/${tab.key}`" class="whitespace-nowrap rounded-md px-3 py-2 text-sm" active-class="bg-cyan-50 font-bold text-cyan-800">{{ tab.label }}</RouterLink>
    </nav>
    <component :is="activeComponent" :domain-id="domainId" :domain="domain" />
  </section>
  <EmptyState v-else-if="!loading" title="Domain not found" message="Return to Domains and choose an existing domain." />
</template>
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'; import { RouterLink, useRoute, useRouter } from 'vue-router'; import EmptyState from '@/components/ui/EmptyState.vue'; import { domainsApi } from '@/lib/api/domains'; import type { Domain } from '@/types';
import DomainOverviewTab from './domain-tabs/DomainOverviewTab.vue'; import DomainDnsTab from './domain-tabs/DomainDnsTab.vue'; import DomainSslTab from './domain-tabs/DomainSslTab.vue'; import DomainCacheTab from './domain-tabs/DomainCacheTab.vue'; import DomainRedirectsTab from './domain-tabs/DomainRedirectsTab.vue'; import DomainPageRulesTab from './domain-tabs/DomainPageRulesTab.vue'; import DomainWafTab from './domain-tabs/DomainWafTab.vue'; import DomainRateLimitsTab from './domain-tabs/DomainRateLimitsTab.vue'; import DomainAnalyticsTab from './domain-tabs/DomainAnalyticsTab.vue';
const route=useRoute();const router=useRouter();const domain=ref<Domain|null>(null);const loading=ref(true);const domainId=computed(()=>String(route.params.domainId));const tabs=[{key:'overview',label:'Overview',component:DomainOverviewTab},{key:'dns',label:'DNS',component:DomainDnsTab},{key:'ssl',label:'SSL',component:DomainSslTab},{key:'cache',label:'Cache',component:DomainCacheTab},{key:'redirects',label:'Redirects',component:DomainRedirectsTab},{key:'page-rules',label:'Page Rules',component:DomainPageRulesTab},{key:'waf',label:'WAF',component:DomainWafTab},{key:'rate-limits',label:'Rate Limits',component:DomainRateLimitsTab},{key:'analytics',label:'Analytics',component:DomainAnalyticsTab}];const activeComponent=computed(()=>tabs.find(t=>t.key===route.params.tab)?.component??DomainOverviewTab);async function load(){loading.value=true;try{domain.value=await domainsApi.get(domainId.value);}catch{domain.value=null;}finally{loading.value=false;}}watch(domainId,load);watch(()=>route.params.tab,(tab)=>{if(tab&&!tabs.some(item=>item.key===tab))router.replace(`/domains/${domainId.value}/overview`);});onMounted(load);
</script>
