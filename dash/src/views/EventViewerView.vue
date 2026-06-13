<template>
  <section class="space-y-6">
    <PageHeader title="Event Viewer" description="Investigate security decisions, purge operations, and other domain activity without digging through raw payloads." eyebrow="Operations">
      <template #actions><button class="button-secondary" :disabled="loading" @click="loadEvents">{{ loading ? 'Refreshing...' : 'Refresh events' }}</button></template>
    </PageHeader>

    <div class="card sticky top-[73px] z-10 grid gap-3 p-4 shadow-md sm:grid-cols-2 xl:grid-cols-6">
      <label class="sm:col-span-2 lg:col-span-1"><span class="field-label">Search events</span><input v-model="search" class="input" type="search" placeholder="Domain, type, action, summary…" /></label>
      <label><span class="field-label">Severity</span><select v-model="severity" class="input"><option value="">All severities</option><option v-for="item in severities" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">Type</span><select v-model="type" class="input"><option value="">All types</option><option v-for="item in types" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">Domain</span><select v-model="domain" class="input"><option value="">All domains</option><option v-for="item in domainNames" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
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
            <tr v-for="event in pagedEvents" :key="event.id" class="hover:bg-slate-50/80 dark:hover:bg-white/[0.03]">
              <td class="table-cell"><StatusBadge :status="event.severity" /></td>
              <td class="table-cell"><b class="text-slate-900 dark:text-white">{{ event.type }}</b><p class="mt-1 max-w-md text-xs text-slate-500">{{ event.summary }}</p></td>
              <td class="table-cell">{{ event.domain }}</td><td class="table-cell">{{ event.decision }}</td><td class="table-cell whitespace-nowrap">{{ event.time }}</td>
              <td class="table-cell text-right"><button class="button-secondary whitespace-nowrap px-3 py-1.5 text-xs" @click="selected = event">View details</button></td>
            </tr>
          </tbody>
        </table>
      </HorizontalScrollFrame>
      <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
        <article v-for="event in pagedEvents" :key="event.id" class="space-y-3 p-4">
          <div class="flex items-start justify-between gap-3"><div><StatusBadge :status="event.severity" /><h2 class="mt-2 font-bold text-slate-950 dark:text-white">{{ event.type }}</h2></div><span class="text-xs text-slate-500">{{ event.time }}</span></div>
          <p class="text-sm text-slate-600 dark:text-slate-300">{{ event.summary }}</p>
          <dl class="grid grid-cols-2 gap-3 text-xs"><div><dt class="text-slate-500">Domain</dt><dd class="mt-1 font-medium">{{ event.domain }}</dd></div><div><dt class="text-slate-500">Decision</dt><dd class="mt-1 font-medium">{{ event.decision }}</dd></div></dl>
          <button class="button-secondary w-full" @click="selected = event">View details</button>
        </article>
      </div>
      <div class="border-t border-slate-200 p-4 dark:border-white/10">
        <PaginationControls :total="filteredEvents.length" :limit="limit" :offset="offset" @update:limit="setLimit" @update:offset="offset=$event" />
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
import { computed, onMounted, ref, watch } from 'vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue'; import EmptyState from '@/components/ui/EmptyState.vue'; import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue'; import PageHeader from '@/components/ui/PageHeader.vue'; import StatusBadge from '@/components/ui/StatusBadge.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { auditLogApi } from '@/lib/api/auditLog'; import { securityEventsApi } from '@/lib/api/securityEvents'; import { formatDate } from '@/lib/utils/format'; import type { AuditEntry, SecurityEvent } from '@/types';
type EventRow = { id: string; domain: string; type: string; severity: string; decision: string; time: string; timestamp: number; summary: string; raw: unknown };
const events = ref<EventRow[]>([]); const loading = ref(true); const error = ref(''); const selected = ref<EventRow | null>(null);
const search = ref(''); const severity = ref(''); const type = ref(''); const domain = ref('');
const fromInput = ref(''); const toInput = ref(''); const limit = ref(25); const offset = ref(0);
const severities = computed(() => unique(events.value.map((e) => e.severity))); const types = computed(() => unique(events.value.map((e) => e.type))); const domainNames = computed(() => unique(events.value.map((e) => e.domain)));
const hasFilters = computed(() => Boolean(search.value || severity.value || type.value || domain.value || fromInput.value || toInput.value));
const filteredEvents = computed(() => events.value.filter((event) => {
  const haystack = `${event.domain} ${event.type} ${event.decision} ${event.summary}`.toLowerCase();
  const from = toEpoch(fromInput.value) * 1000; const to = toEpoch(toInput.value) * 1000;
  return (!search.value || haystack.includes(search.value.toLowerCase())) &&
    (!severity.value || event.severity === severity.value) &&
    (!type.value || event.type === type.value) &&
    (!domain.value || event.domain === domain.value) &&
    (!from || event.timestamp >= from) &&
    (!to || event.timestamp <= to);
}).sort((a, b) => b.timestamp - a.timestamp));
const pagedEvents = computed(() => filteredEvents.value.slice(offset.value, offset.value + limit.value));
watch([search, severity, type, domain, fromInput, toInput], () => { offset.value = 0; });
onMounted(loadEvents);
async function loadEvents() {
  loading.value = true; error.value = '';
  try {
    const from = toEpoch(fromInput.value); const to = toEpoch(toInput.value);
    const [security, audit] = await Promise.all([
      securityEventsApi.list({ from, to, limit: 500, offset: 0 }),
      auditLogApi.list({ from, to, limit: 500, offset: 0 }),
    ]);
    events.value = [
      ...security.items.map(normalizeSecurity),
      ...audit.items.filter((entry) => !entry.type).map(normalizeAudit),
    ];
  } catch (cause) { error.value = cause instanceof Error ? cause.message : 'An unexpected request error occurred.'; } finally { loading.value = false; }
}
function normalizeSecurity(event: SecurityEvent): EventRow { const timestamp = toTimestamp(event.timestamp ?? event.created_at); const decision = event.decision ?? event.action ?? 'observed'; return { id: event.id, domain: event.domain_name ?? event.domain_id ?? 'Platform', type: event.type ?? 'Security event', severity: event.severity ?? 'info', decision, time: formatDate(event.timestamp ?? event.created_at), timestamp, summary: `${decision} decision recorded by ${event.type ?? 'the security engine'}.`, raw: event }; }
function normalizeAudit(entry: AuditEntry): EventRow { const timestamp = toTimestamp(entry.created_at); return { id: entry.id, domain: entry.domain_name ?? entry.domain_id ?? 'Platform', type: entry.action, severity: 'info', decision: entry.resource_type, time: formatDate(entry.created_at), timestamp, summary: `${entry.action} changed ${entry.resource_type}${entry.resource_id ? ` ${entry.resource_id}` : ''}.`, raw: entry }; }
function toTimestamp(value?: number | string) { if (!value) return 0; if (typeof value === 'number') return value < 10_000_000_000 ? value * 1000 : value; return new Date(value).getTime() || 0; }
function toEpoch(value: string) { return value ? Math.floor(new Date(value).getTime() / 1000) : 0; }
function unique(values: string[]) { return [...new Set(values)].sort(); }
function clearFilters() { search.value = ''; severity.value = ''; type.value = ''; domain.value = ''; fromInput.value = ''; toInput.value = ''; offset.value = 0; void loadEvents(); }
function setLimit(value: number) { limit.value = value; offset.value = 0; }
async function copyJson(value: unknown) { await navigator.clipboard.writeText(JSON.stringify(value, null, 2)); }
</script>
