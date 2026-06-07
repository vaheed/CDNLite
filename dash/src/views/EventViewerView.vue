<template>
  <section class="space-y-6">
    <PageHeader title="Event Viewer" description="Investigate security decisions, purge operations, and other domain activity without digging through raw payloads." eyebrow="Operations">
      <template #actions><button class="button-secondary" :disabled="loading" @click="loadEvents">{{ loading ? 'Refreshing...' : 'Refresh events' }}</button></template>
    </PageHeader>

    <div class="card sticky top-[73px] z-10 grid gap-3 p-4 shadow-md sm:grid-cols-2 lg:grid-cols-4">
      <label class="sm:col-span-2 lg:col-span-1"><span class="field-label">Search events</span><input v-model="search" class="input" type="search" placeholder="Domain, type, action, summary…" /></label>
      <label><span class="field-label">Severity</span><select v-model="severity" class="input"><option value="">All severities</option><option v-for="item in severities" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">Type</span><select v-model="type" class="input"><option value="">All types</option><option v-for="item in types" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">Domain</span><select v-model="domain" class="input"><option value="">All domains</option><option v-for="item in domainNames" :key="item">{{ item }}</option></select></label>
    </div>

    <div v-if="error" class="state-error" role="alert"><b>Events could not be loaded.</b> {{ error }} <button class="ml-2 underline" @click="loadEvents">Try again</button></div>
    <LoadingSkeleton v-else-if="loading" />
    <EmptyState v-else-if="filteredEvents.length === 0" title="No events found" message="No events match the current filters. Clear a filter or refresh to check for new activity.">
      <button v-if="hasFilters" class="button-secondary" @click="clearFilters">Clear filters</button>
    </EmptyState>
    <div v-else class="card overflow-hidden">
      <HorizontalScrollFrame class="hidden md:block" :watch-key="filteredEvents.length">
        <table class="w-full text-left text-sm">
          <thead class="table-head"><tr><th>Severity</th><th>Event</th><th>Domain</th><th>Decision</th><th>Timestamp</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody class="divide-y divide-slate-100 dark:divide-white/5">
            <tr v-for="event in filteredEvents" :key="event.id" class="hover:bg-slate-50/80 dark:hover:bg-white/[0.03]">
              <td class="table-cell"><StatusBadge :status="event.severity" /></td>
              <td class="table-cell"><b class="text-slate-900 dark:text-white">{{ event.type }}</b><p class="mt-1 max-w-md text-xs text-slate-500">{{ event.summary }}</p></td>
              <td class="table-cell">{{ event.domain }}</td><td class="table-cell">{{ event.decision }}</td><td class="table-cell whitespace-nowrap">{{ event.time }}</td>
              <td class="table-cell text-right"><button class="button-secondary whitespace-nowrap px-3 py-1.5 text-xs" @click="selected = event">View details</button></td>
            </tr>
          </tbody>
        </table>
      </HorizontalScrollFrame>
      <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
        <article v-for="event in filteredEvents" :key="event.id" class="space-y-3 p-4">
          <div class="flex items-start justify-between gap-3"><div><StatusBadge :status="event.severity" /><h2 class="mt-2 font-bold text-slate-950 dark:text-white">{{ event.type }}</h2></div><span class="text-xs text-slate-500">{{ event.time }}</span></div>
          <p class="text-sm text-slate-600 dark:text-slate-300">{{ event.summary }}</p>
          <dl class="grid grid-cols-2 gap-3 text-xs"><div><dt class="text-slate-500">Domain</dt><dd class="mt-1 font-medium">{{ event.domain }}</dd></div><div><dt class="text-slate-500">Decision</dt><dd class="mt-1 font-medium">{{ event.decision }}</dd></div></dl>
          <button class="button-secondary w-full" @click="selected = event">View details</button>
        </article>
      </div>
    </div>

    <DetailsDrawer :open="Boolean(selected)" :title="selected?.type ?? 'Event details'" @close="selected = null">
      <div v-if="selected" class="space-y-6">
        <div class="flex flex-wrap items-center gap-2"><StatusBadge :status="selected.severity" /><span class="text-sm text-slate-500">{{ selected.time }}</span></div>
        <p class="text-sm leading-6 text-slate-700 dark:text-slate-300">{{ selected.summary }}</p>
        <dl class="grid gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2 dark:bg-white/[0.04]">
          <div><dt class="text-slate-500">Domain</dt><dd class="mt-1 font-semibold">{{ selected.domain }}</dd></div>
          <div><dt class="text-slate-500">Decision / action</dt><dd class="mt-1 font-semibold">{{ selected.decision }}</dd></div>
          <div><dt class="text-slate-500">Type</dt><dd class="mt-1 font-semibold">{{ selected.type }}</dd></div>
          <div><dt class="text-slate-500">Event ID</dt><dd class="mt-1 break-all font-mono text-xs">{{ selected.id }}</dd></div>
        </dl>
        <details class="rounded-xl border border-slate-200 dark:border-white/10">
          <summary class="cursor-pointer p-4 font-semibold">Raw JSON</summary>
          <div class="border-t border-slate-200 p-4 dark:border-white/10"><button class="button-secondary mb-3" @click="copyJson(selected.raw)">Copy JSON</button><pre class="max-h-96 overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ JSON.stringify(selected.raw, null, 2) }}</pre></div>
        </details>
      </div>
    </DetailsDrawer>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue'; import EmptyState from '@/components/ui/EmptyState.vue'; import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue'; import PageHeader from '@/components/ui/PageHeader.vue'; import StatusBadge from '@/components/ui/StatusBadge.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import { domainsApi } from '@/lib/api/domains'; import { loadSecurityEventsForDomains } from '@/lib/api/securityEvents'; import { purgeApi } from '@/lib/api/purge'; import { formatDate } from '@/lib/utils/format'; import type { Domain, SecurityEvent } from '@/types';
type EventRow = { id: string; domain: string; type: string; severity: string; decision: string; time: string; timestamp: number; summary: string; raw: unknown };
const events = ref<EventRow[]>([]); const loading = ref(true); const error = ref(''); const selected = ref<EventRow | null>(null);
const search = ref(''); const severity = ref(''); const type = ref(''); const domain = ref('');
const severities = computed(() => unique(events.value.map((e) => e.severity))); const types = computed(() => unique(events.value.map((e) => e.type))); const domainNames = computed(() => unique(events.value.map((e) => e.domain)));
const hasFilters = computed(() => Boolean(search.value || severity.value || type.value || domain.value));
const filteredEvents = computed(() => events.value.filter((event) => {
  const haystack = `${event.domain} ${event.type} ${event.decision} ${event.summary}`.toLowerCase();
  return (!search.value || haystack.includes(search.value.toLowerCase())) && (!severity.value || event.severity === severity.value) && (!type.value || event.type === type.value) && (!domain.value || event.domain === domain.value);
}).sort((a, b) => b.timestamp - a.timestamp));
onMounted(loadEvents);
async function loadEvents() {
  loading.value = true; error.value = '';
  try {
    const domains = await domainsApi.list();
    const [security, purges] = await Promise.all([loadSecurityEventsForDomains(domains), Promise.all(domains.map((item) => purgeApi.list(item.id))).then((items) => items.flat())]);
    const names = new Map(domains.map((item) => [item.id, item.domain]));
    events.value = [
      ...security.map((event) => normalizeSecurity(event, names)),
      ...purges.map((purge) => { const timestamp = toTimestamp(purge.created_at); return { id: purge.id, domain: names.get(purge.domain_id ?? '') ?? purge.domain_id ?? 'Unknown domain', type: `Purge: ${purge.type}`, severity: purge.status === 'failed' ? 'critical' : purge.status === 'pending' ? 'warning' : 'info', decision: purge.status ?? 'submitted', time: formatDate(purge.created_at), timestamp, summary: `Cache purge ${purge.status ?? 'submitted'} for ${purge.value || purge.type}.`, raw: purge }; }),
    ];
  } catch (cause) { error.value = cause instanceof Error ? cause.message : 'An unexpected request error occurred.'; } finally { loading.value = false; }
}
function normalizeSecurity(event: SecurityEvent, names: Map<string, string>): EventRow { const timestamp = toTimestamp(event.timestamp ?? event.created_at); const decision = event.decision ?? event.action ?? 'observed'; return { id: event.id, domain: event.domain_name ?? names.get(event.domain_id ?? '') ?? event.domain_id ?? 'Unknown domain', type: event.type ?? 'Security event', severity: event.severity ?? 'info', decision, time: formatDate(event.timestamp ?? event.created_at), timestamp, summary: `${decision} decision recorded by ${event.type ?? 'the security engine'}.`, raw: event }; }
function toTimestamp(value?: number | string) { if (!value) return 0; if (typeof value === 'number') return value < 10_000_000_000 ? value * 1000 : value; return new Date(value).getTime() || 0; }
function unique(values: string[]) { return [...new Set(values)].sort(); }
function clearFilters() { search.value = ''; severity.value = ''; type.value = ''; domain.value = ''; }
async function copyJson(value: unknown) { await navigator.clipboard.writeText(JSON.stringify(value, null, 2)); }
</script>
