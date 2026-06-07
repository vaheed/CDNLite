<template>
  <section v-if="domain" class="space-y-5 pb-8">
    <header class="domain-header">
      <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
          <div class="mb-3 flex items-center gap-2 text-sm text-slate-500">
            <RouterLink to="/domains" class="font-medium hover:text-cyan-700 dark:hover:text-cyan-300">Domains</RouterLink>
            <span aria-hidden="true">/</span>
            <span class="truncate">{{ domain.domain }}</span>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="grid h-11 w-11 place-items-center rounded-xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-200 dark:bg-cyan-400/10 dark:text-cyan-300 dark:ring-cyan-300/20">
              <Globe2 class="h-5 w-5" />
            </div>
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{{ domain.domain }}</h1>
                <StatusBadge :status="domainStatus" :label="statusLabel" />
              </div>
              <p class="mt-1 text-sm text-slate-500">{{ domain.name }}</p>
            </div>
          </div>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <button class="button-secondary" :disabled="loading" @click="load"><RefreshCw class="h-4 w-4" /> Refresh</button>
          <ReportExportButton title="Domain detail" :data="{ domain }" />
        </div>
      </div>
      <div class="mt-5 grid gap-2 border-t border-slate-200 pt-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-white/10">
        <div v-for="health in healthItems" :key="health.label" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/[0.04]">
          <span class="text-xs font-medium text-slate-500">{{ health.label }}</span>
          <StatusBadge :status="health.status" :label="health.value" />
        </div>
      </div>
    </header>

    <nav class="domain-tabs" aria-label="Domain management" role="tablist">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.key"
        :to="`/domains/${domainId}/${tab.key}`"
        class="domain-tab"
        active-class="domain-tab-active"
        role="tab"
        :aria-selected="route.params.tab === tab.key"
      >
        <component :is="tab.icon" class="h-4 w-4" />
        <span>{{ tab.label }}</span>
      </RouterLink>
    </nav>

    <component :is="activeComponent" :domain-id="domainId" :domain="domain" />
  </section>
  <EmptyState v-else-if="!loading" title="Domain not found" message="Return to Domains and choose an existing domain." />
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
  Activity, BarChart3, Database, Gauge, Globe2, LockKeyhole,
  Network, RefreshCw, Route, ShieldCheck,
} from 'lucide-vue-next';
import EmptyState from '@/components/ui/EmptyState.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { domainsApi } from '@/lib/api/domains';
import type { Domain, Severity } from '@/types';
import DomainOverviewTab from './domain-tabs/DomainOverviewTab.vue';
import DomainDnsTab from './domain-tabs/DomainDnsTab.vue';
import DomainSslTab from './domain-tabs/DomainSslTab.vue';
import DomainCacheTab from './domain-tabs/DomainCacheTab.vue';
import DomainRedirectsTab from './domain-tabs/DomainRedirectsTab.vue';
import DomainPageRulesTab from './domain-tabs/DomainPageRulesTab.vue';
import DomainWafTab from './domain-tabs/DomainWafTab.vue';
import DomainRateLimitsTab from './domain-tabs/DomainRateLimitsTab.vue';
import DomainAnalyticsTab from './domain-tabs/DomainAnalyticsTab.vue';

const route = useRoute();
const router = useRouter();
const domain = ref<Domain | null>(null);
const loading = ref(true);
const domainId = computed(() => String(route.params.domainId));
const tabs = [
  { key: 'overview', label: 'Overview', icon: Activity, component: DomainOverviewTab },
  { key: 'dns', label: 'DNS', icon: Network, component: DomainDnsTab },
  { key: 'ssl', label: 'SSL', icon: LockKeyhole, component: DomainSslTab },
  { key: 'cache', label: 'Cache', icon: Database, component: DomainCacheTab },
  { key: 'redirects', label: 'Redirects', icon: Route, component: DomainRedirectsTab },
  { key: 'page-rules', label: 'Page Rules', icon: RefreshCw, component: DomainPageRulesTab },
  { key: 'waf', label: 'WAF', icon: ShieldCheck, component: DomainWafTab },
  { key: 'rate-limits', label: 'Rate Limits', icon: Gauge, component: DomainRateLimitsTab },
  { key: 'analytics', label: 'Analytics', icon: BarChart3, component: DomainAnalyticsTab },
];
const activeComponent = computed(() => tabs.find((tab) => tab.key === route.params.tab)?.component ?? DomainOverviewTab);
const statusLabel = computed(() => String(domain.value?.status ?? 'unknown').replaceAll('_', ' '));
const domainStatus = computed<Severity>(() => {
  if (domain.value?.status === 'active') return 'healthy';
  if (domain.value?.status === 'error') return 'critical';
  return 'warning';
});
const healthItems = computed(() => [
  { label: 'DNS', value: String(domain.value?.nameserver_status ?? 'unknown').replaceAll('_', ' '), status: domain.value?.nameserver_status === 'verified' ? 'healthy' : 'warning' },
  { label: 'SSL', value: domain.value?.status === 'active' ? 'Ready' : 'Pending', status: domain.value?.status === 'active' ? 'healthy' : 'warning' },
  { label: 'Cache', value: 'Available', status: 'healthy' },
  { label: 'Edge', value: domain.value?.status === 'active' ? 'Online' : 'Pending', status: domain.value?.status === 'active' ? 'healthy' : 'warning' },
  { label: 'WAF', value: 'Available', status: 'info' },
]);

async function load() {
  loading.value = true;
  try { domain.value = await domainsApi.get(domainId.value); }
  catch { domain.value = null; }
  finally { loading.value = false; }
}

watch(domainId, load);
watch(() => route.params.tab, (tab) => {
  if (tab && !tabs.some((item) => item.key === tab)) router.replace(`/domains/${domainId.value}/overview`);
});
onMounted(load);
</script>
