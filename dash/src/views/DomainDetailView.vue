<template>
  <section v-if="domain" class="space-y-5">
    <header class="domain-header">
      <div class="min-w-0">
        <RouterLink to="/domains" class="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-cyan-700">
          <ArrowLeft class="h-4 w-4" /> Domains
        </RouterLink>
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="truncate text-2xl font-bold text-slate-950 dark:text-white">{{ domain.domain }}</h1>
          <StatusBadge :status="domainStatus" :label="statusLabel" />
        </div>
        <p class="mt-1 text-sm text-slate-500">{{ domain.name }}</p>
      </div>
      <ReportExportButton title="Domain detail" :data="{ domain }" />
    </header>

    <nav class="domain-tabs" aria-label="Domain management">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.key"
        :to="`/domains/${domainId}/${tab.key}`"
        class="domain-tab"
        active-class="domain-tab-active"
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
  Activity, ArrowLeft, BarChart3, Database, Gauge, Globe2, LockKeyhole,
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
