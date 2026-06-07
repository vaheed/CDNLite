<template>
  <div class="space-y-5">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="item in summary" :key="item.label" class="metric-panel">
        <div class="flex items-center justify-between">
          <p class="text-xs font-semibold uppercase text-slate-500">{{ item.label }}</p>
          <component :is="item.icon" class="h-4 w-4 text-slate-400" />
        </div>
        <p class="mt-3 text-lg font-semibold capitalize text-slate-950 dark:text-white">{{ item.value }}</p>
      </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.15fr_.85fr]">
      <section class="panel-section">
        <div class="section-heading">
          <div><h2>Nameservers</h2><p>Authoritative delegation for this domain.</p></div>
          <StatusBadge :status="nameserverSeverity" :label="nameserverLabel" />
        </div>
        <div v-if="domain.nameservers?.length" class="divide-y divide-slate-100 dark:divide-white/5">
          <div v-for="nameserver in domain.nameservers" :key="nameserver.hostname" class="flex items-center justify-between gap-4 py-4">
            <div class="min-w-0">
              <p class="truncate font-mono text-sm font-medium">{{ nameserver.hostname }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ nameserver.observed ? 'Delegation observed' : 'Waiting for DNS delegation' }}</p>
            </div>
            <CheckCircle2 v-if="nameserver.observed" class="h-5 w-5 shrink-0 text-emerald-600" />
            <Clock3 v-else class="h-5 w-5 shrink-0 text-amber-500" />
          </div>
        </div>
        <p v-else class="py-8 text-center text-sm text-slate-500">No nameservers are configured in platform settings.</p>
      </section>

      <section class="panel-section">
        <div class="section-heading">
          <div><h2>Origin and delivery</h2><p>Traffic settings are managed per DNS record.</p></div>
          <Server class="h-5 w-5 text-slate-400" />
        </div>
        <dl class="space-y-4 py-2 text-sm">
          <div class="flex justify-between gap-4"><dt class="text-slate-500">Origin configuration</dt><dd class="font-medium">DNS records</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-slate-500">PowerDNS zone</dt><dd class="font-medium">{{ domain.powerdns_zone_created ? 'Ready' : 'Not created' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-slate-500">Last nameserver check</dt><dd class="font-medium">{{ lastCheck }}</dd></div>
        </dl>
        <RouterLink :to="`/domains/${domain.id}/dns`" class="button-secondary mt-4 w-full">
          <Network class="h-4 w-4" /> Manage DNS and origins
        </RouterLink>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { CheckCircle2, Clock3, Globe2, Network, Server, ShieldCheck } from 'lucide-vue-next';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import type { Domain, Severity } from '@/types';

const props = defineProps<{ domain: Domain }>();
const nameserverLabel = computed(() => String(props.domain.nameserver_status ?? 'unknown').replaceAll('_', ' '));
const nameserverSeverity = computed<Severity>(() => props.domain.nameserver_status === 'verified' ? 'healthy' : 'warning');
const lastCheck = computed(() => props.domain.last_ns_check_at ? new Date(props.domain.last_ns_check_at * 1000).toLocaleString() : 'Not checked');
const summary = computed(() => [
  { label: 'Domain status', value: String(props.domain.status ?? 'unknown').replaceAll('_', ' '), icon: Globe2 },
  { label: 'Nameservers', value: nameserverLabel.value, icon: Network },
  { label: 'DNS provider', value: props.domain.powerdns_zone_created ? 'PowerDNS ready' : 'Local only', icon: Server },
  { label: 'Protection', value: props.domain.status === 'active' ? 'Ready' : 'Pending setup', icon: ShieldCheck },
]);
</script>
