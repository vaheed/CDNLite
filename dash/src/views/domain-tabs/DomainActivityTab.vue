<template>
  <div class="space-y-6">
    <div class="panel-section flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-base font-semibold">Activity view</h2>
        <p class="text-sm text-slate-500">Simple explains outcomes. Advanced keeps request IDs, raw details, filters, and export.</p>
      </div>
      <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-white/10 dark:bg-white/5">
        <button type="button" class="rounded-md px-3 py-1.5 text-sm font-semibold" :class="activityMode === 'simple' ? 'bg-white text-slate-950 shadow-sm dark:bg-white/15 dark:text-white' : 'text-slate-500'" @click="activityMode='simple'">Simple view</button>
        <button type="button" class="rounded-md px-3 py-1.5 text-sm font-semibold" :class="activityMode === 'advanced' ? 'bg-white text-slate-950 shadow-sm dark:bg-white/15 dark:text-white' : 'text-slate-500'" @click="activityMode='advanced'">Advanced view</button>
      </div>
    </div>
    <form class="panel-section grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="applyFilters">
      <label><span class="field-label">Search details</span><input v-model="search" class="input" type="search" placeholder="Request ID, path, action, origin..." /></label>
      <label><span class="field-label">Event type</span><select v-model="typeFilter" class="input"><option value="">All activity</option><option value="request">Requests</option><option value="error">Errors</option><option value="audit">Changes</option><option value="security">Security</option></select></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
      <div class="flex items-end gap-2"><button class="button-primary flex-1">Apply</button><button type="button" class="button-secondary" @click="clearFilters">Clear</button><button type="button" class="button-secondary" @click="exportCurrent">Export JSON</button></div>
    </form>
    <form v-if="activityMode === 'advanced'" class="panel-section flex flex-col gap-3 md:flex-row md:items-end" @submit.prevent="findByRequestId">
      <label class="flex-1"><span class="field-label">Request-id lookup</span><input v-model="requestIdSearch" class="input font-mono" type="search" placeholder="Paste the request id from a 5xx error page" /></label>
      <button class="button-secondary" :disabled="requestLookupBusy">{{ requestLookupBusy ? 'Searching...' : 'Find request' }}</button>
    </form>

    <div v-if="error" class="state-error">{{ error }}</div>
    <LoadingSkeleton v-else-if="loading" />
    <template v-else>
      <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        <div class="panel-section"><p class="field-label">Total requests</p><p class="mt-1 text-2xl font-bold">{{ summary?.total_requests ?? 0 }}</p></div>
        <div class="panel-section"><p class="field-label">Forwarded</p><p class="mt-1 text-2xl font-bold">{{ summary?.forwarded_requests ?? 0 }}</p></div>
        <div class="panel-section"><p class="field-label">Cache hit ratio</p><p class="mt-1 text-2xl font-bold">{{ percent(summary?.cache_hit_ratio ?? 0) }}</p></div>
        <div class="panel-section"><p class="field-label">5xx / 502</p><p class="mt-1 text-2xl font-bold">{{ summary?.status_counts?.['5xx'] ?? 0 }} / {{ summary?.status_counts?.['502'] ?? 0 }}</p></div>
        <div class="panel-section"><p class="field-label">Bytes out</p><p class="mt-1 text-2xl font-bold">{{ formatBytes(summary?.bytes_out ?? 0) }}</p></div>
      </section>

      <section v-if="activityMode === 'simple'" class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Beginner Activity summary</h2><p>{{ summary?.beginner?.headline || 'CDNLite is monitoring this site.' }}</p></div></div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <div v-for="card in simpleCards" :key="card.key" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <p class="field-label">{{ card.label }}</p>
            <p class="mt-1 text-2xl font-bold">{{ card.count }}</p>
            <p class="mt-2 text-xs text-slate-500">{{ simpleCardHint(card.category) }}</p>
          </div>
        </div>
        <div v-if="summary?.beginner?.recommendations?.length" class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
          <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">Recommended action</p>
          <button v-for="recommendation in summary.beginner.recommendations" :key="recommendation.type" type="button" class="mt-2 block text-left text-sm text-amber-800 underline decoration-amber-400 underline-offset-2 dark:text-amber-100" @click="selected=recommendation">
            {{ recommendation.label }} — {{ recommendation.reason }}
          </button>
        </div>
      </section>

      <section class="panel-section space-y-4">
        <div class="section-heading"><div><h2>{{ activityMode === 'simple' ? 'Readable Activity cards' : 'Activity timeline' }}</h2><p>{{ activityMode === 'simple' ? 'Friendly labels grouped by protection outcome.' : 'Requests, origin errors, DNS/SSL changes, and security events in one stream.' }}</p></div></div>
        <EmptyState v-if="!timeline.items.length" title="No timeline events" message="No domain activity matches the current filters." />
        <div v-else class="space-y-3">
          <button v-for="item in timeline.items" :key="item.id" class="w-full rounded-lg border border-slate-200 bg-white p-4 text-left transition hover:border-sky-300 dark:border-white/10 dark:bg-white/5" @click="selected=item">
            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(item.type)">{{ activityMode === 'simple' ? (item.friendly?.category || item.type) : item.type }}</span>
              <span class="text-sm text-slate-500">{{ formatDate(item.ts) }}</span>
              <span v-if="activityMode === 'advanced' && item.request_id" class="font-mono text-xs text-slate-500">{{ item.request_id }}</span>
            </div>
            <p class="mt-2 font-semibold text-slate-950 dark:text-white">{{ activityMode === 'simple' ? (item.friendly?.label || item.title) : item.title }}</p>
            <p v-if="item.summary" class="mt-1 text-sm text-slate-500">{{ activityMode === 'simple' ? (item.friendly?.summary || item.summary) : item.summary }}</p>
            <p v-if="activityMode === 'simple' && item.friendly?.recommendation" class="mt-2 text-sm font-semibold text-sky-700 dark:text-sky-200">{{ item.friendly.recommendation }}</p>
          </button>
        </div>
        <PaginationControls :total="timeline.total" :limit="timelineLimit" :offset="timelineOffset" @update:limit="setTimelineLimit" @update:offset="setTimelineOffset" />
      </section>

      <section v-if="activityMode === 'advanced'" class="grid gap-4 xl:grid-cols-4">
        <div class="panel-section space-y-3">
          <h2 class="text-base font-semibold">Top paths</h2>
          <p v-for="row in summary?.top_paths || []" :key="row.value" class="flex justify-between gap-3 text-sm"><span class="truncate font-mono">{{ row.value }}</span><b>{{ row.count }}</b></p>
        </div>
        <div class="panel-section space-y-3">
          <h2 class="text-base font-semibold">Top visitor countries</h2>
          <p v-for="row in summary?.top_countries || []" :key="row.value" class="flex justify-between gap-3 text-sm"><span class="truncate font-mono">{{ countryLabel(row.value) }}</span><b>{{ row.count }}</b></p>
        </div>
        <div class="panel-section space-y-3">
          <h2 class="text-base font-semibold">Top origins</h2>
          <p v-for="row in summary?.top_origins || []" :key="row.value" class="flex justify-between gap-3 text-sm"><span class="truncate font-mono">{{ row.value }}</span><b>{{ row.count }}</b></p>
        </div>
        <div class="panel-section space-y-3">
          <h2 class="text-base font-semibold">Top edge nodes</h2>
          <p v-for="row in summary?.top_edge_nodes || []" :key="row.value" class="flex justify-between gap-3 text-sm"><span class="truncate font-mono">{{ row.value }}</span><b>{{ row.count }}</b></p>
        </div>
      </section>

      <section v-if="activityMode === 'advanced' && showErrorSections" class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Recent origin errors</h2><p>Latest 5xx, router, and upstream failures for quick request-id correlation.</p></div></div>
        <EmptyState v-if="!summary?.recent_origin_errors?.length" title="No recent origin errors" message="No origin or router failures match this period." />
        <HorizontalScrollFrame v-else :watch-key="summary.recent_origin_errors.length">
          <table class="w-full min-w-[840px] text-left text-sm">
            <thead class="table-head"><tr><th>Time</th><th>Request</th><th>Status</th><th>Origin</th><th>Failure</th><th>Request ID</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
              <tr v-for="request in summary.recent_origin_errors" :key="request.id">
                <td class="table-cell whitespace-nowrap">{{ formatDate(request.ts) }}</td>
                <td class="table-cell"><b>{{ request.method || 'GET' }}</b> <span class="font-mono text-xs">{{ request.host }}{{ request.path }}</span></td>
                <td class="table-cell">{{ request.status }}</td>
                <td class="table-cell font-mono text-xs">{{ request.origin_id || 'none' }}</td>
                <td class="table-cell">{{ request.router_error || request.upstream_status || 'upstream error' }}</td>
                <td class="table-cell"><button class="button-secondary px-3 py-1.5 text-xs" @click="selected=request">{{ request.request_id || 'View JSON' }}</button></td>
              </tr>
            </tbody>
          </table>
        </HorizontalScrollFrame>
      </section>

      <section v-if="activityMode === 'advanced' && showRequestSections" class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Recent edge requests</h2><p>Request, cache, router, and origin forwarding details captured from edge metrics.</p></div></div>
        <EmptyState v-if="!requests.items.length" title="No request details" message="No edge request metrics have been ingested for this domain yet." />
        <HorizontalScrollFrame v-else :watch-key="requests.items.length">
          <table class="w-full min-w-[960px] text-left text-sm">
            <thead class="table-head"><tr><th>Time</th><th>Request</th><th>Status</th><th>Cache</th><th>Origin</th><th>Upstream</th><th>Request ID</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
              <tr v-for="request in requests.items" :key="request.id">
                <td class="table-cell whitespace-nowrap">{{ formatDate(request.ts) }}</td>
                <td class="table-cell"><b>{{ request.method || 'GET' }}</b> <span class="font-mono text-xs">{{ request.host }}{{ request.path }}</span></td>
                <td class="table-cell">{{ request.status }}</td>
                <td class="table-cell">{{ request.cache_status || 'UNKNOWN' }}</td>
                <td class="table-cell"><span class="font-mono text-xs">{{ request.origin_id || 'none' }}</span><span v-if="request.origin_host" class="block text-xs text-slate-500">{{ request.origin_host }}</span></td>
                <td class="table-cell">{{ request.upstream_status || 'none' }}<span v-if="request.upstream_response_time_ms !== null && request.upstream_response_time_ms !== undefined" class="block text-xs text-slate-500">{{ request.upstream_response_time_ms }} ms</span><span v-if="request.router_error" class="block text-xs text-rose-600">{{ request.router_error }}</span></td>
                <td class="table-cell"><button class="button-secondary px-3 py-1.5 text-xs" @click="selected=request">{{ request.request_id || 'View JSON' }}</button></td>
              </tr>
            </tbody>
          </table>
        </HorizontalScrollFrame>
        <PaginationControls :total="requests.total" :limit="requestsLimit" :offset="requestsOffset" @update:limit="setRequestsLimit" @update:offset="setRequestsOffset" />
      </section>

      <section v-if="activityMode === 'advanced' && showSecuritySections" class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Security events</h2><p>WAF, rate-limit, and Geo decisions for this domain.</p></div></div>
        <EmptyState v-if="!security.items.length" title="No security events" message="No domain security events match the selected period." />
        <HorizontalScrollFrame v-else :watch-key="security.items.length">
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="table-head"><tr><th>Time</th><th>Type</th><th>Edge</th><th>Action</th><th>Details</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
              <tr v-for="event in security.items" :key="event.id">
                <td class="table-cell whitespace-nowrap">{{ formatDate(event.created_at) }}</td>
                <td class="table-cell font-semibold">{{ event.type || 'security' }}</td>
                <td class="table-cell">{{ event.actor_id || 'Unknown' }}</td>
                <td class="table-cell">{{ event.action || event.decision || 'observed' }}</td>
                <td class="table-cell"><button class="button-secondary px-3 py-1.5 text-xs" @click="selected=event">View JSON</button></td>
              </tr>
            </tbody>
          </table>
        </HorizontalScrollFrame>
        <PaginationControls :total="security.total" :limit="securityLimit" :offset="securityOffset" @update:limit="setSecurityLimit" @update:offset="setSecurityOffset" />
      </section>

      <section v-if="activityMode === 'advanced' && showAuditSections" class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Change log</h2><p>Administrative and automated changes scoped to this domain.</p></div></div>
        <EmptyState v-if="!audit.items.length" title="No changes" message="No domain changes match the selected period." />
        <HorizontalScrollFrame v-else :watch-key="audit.items.length">
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="table-head"><tr><th>Time</th><th>Actor</th><th>Action</th><th>Resource</th><th>Details</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
              <tr v-for="entry in audit.items" :key="entry.id">
                <td class="table-cell whitespace-nowrap">{{ formatDate(entry.created_at) }}</td>
                <td class="table-cell">{{ entry.actor_id || entry.actor_type }}</td>
                <td class="table-cell font-semibold">{{ entry.action }}</td>
                <td class="table-cell">{{ entry.resource_type }}</td>
                <td class="table-cell"><button class="button-secondary px-3 py-1.5 text-xs" @click="selected=entry">View JSON</button></td>
              </tr>
            </tbody>
          </table>
        </HorizontalScrollFrame>
        <PaginationControls :total="audit.total" :limit="auditLimit" :offset="auditOffset" @update:limit="setAuditLimit" @update:offset="setAuditOffset" />
      </section>
    </template>

    <DetailsDrawer :open="Boolean(selected)" title="Domain activity details" @close="selected=null">
      <pre v-if="selected" class="max-h-[70vh] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ JSON.stringify(selected, null, 2) }}</pre>
    </DetailsDrawer>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { auditLogApi } from '@/lib/api/auditLog';
import { securityEventsApi } from '@/lib/api/securityEvents';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import { usageApi } from '@/lib/api/usage';
import { formatDate } from '@/lib/utils/format';
import type { ActivityExport, ActivitySummary, ActivityTimeline, ActivityTimelineItem, AuditEntry, PaginatedResult, RequestActivity, SecurityEvent } from '@/types';

const props = defineProps<{ domainId: string }>();
const loading = ref(true);
const error = ref('');
const selected = ref<AuditEntry | RequestActivity | SecurityEvent | ActivityTimelineItem | ActivityExport | Record<string, unknown> | null>(null);
const activityMode = ref<'simple' | 'advanced'>('simple');
const search = ref('');
const typeFilter = ref('');
const requestIdSearch = ref('');
const requestLookupBusy = ref(false);
const fromInput = ref('');
const toInput = ref('');
const timelineLimit = ref(25);
const timelineOffset = ref(0);
const requestsLimit = ref(25);
const requestsOffset = ref(0);
const securityLimit = ref(25);
const securityOffset = ref(0);
const auditLimit = ref(25);
const auditOffset = ref(0);
const security = ref<PaginatedResult<SecurityEvent>>({ items: [], total: 0, limit: 25, offset: 0 });
const audit = ref<PaginatedResult<AuditEntry>>({ items: [], total: 0, limit: 25, offset: 0 });
const requests = ref<PaginatedResult<RequestActivity>>({ items: [], total: 0, limit: 25, offset: 0 });
const summary = ref<ActivitySummary | null>(null);
const timeline = ref<ActivityTimeline>({ items: [], total: 0, limit: 25, offset: 0, cursor: null });
const simpleCards = computed(() => summary.value?.beginner?.cards || []);
const showRequestSections = computed(() => typeFilter.value === '' || typeFilter.value === 'request' || typeFilter.value === 'error');
const showErrorSections = computed(() => typeFilter.value === '' || typeFilter.value === 'error');
const showSecuritySections = computed(() => typeFilter.value === '' || typeFilter.value === 'security');
const showAuditSections = computed(() => typeFilter.value === '' || typeFilter.value === 'audit');

watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainActivity(props.domainId), queryKeys.auditLog()], load);
onMounted(load);

async function load() {
  loading.value = true;
  error.value = '';
  const from = toEpoch(fromInput.value);
  const to = toEpoch(toInput.value);
  try {
    [summary.value, timeline.value, requests.value, security.value, audit.value] = await Promise.all([
      usageApi.activitySummary(props.domainId, { from, to }),
      usageApi.activityTimeline(props.domainId, { search: search.value, type: typeFilter.value, from, to, limit: timelineLimit.value, offset: timelineOffset.value }),
      usageApi.recentRequests(props.domainId, { search: search.value, type: typeFilter.value, from, to, limit: requestsLimit.value, offset: requestsOffset.value }),
      securityEventsApi.list({ domain_id: props.domainId, search: search.value, from, to, limit: securityLimit.value, offset: securityOffset.value }),
      auditLogApi.list({ domain_id: props.domainId, search: search.value, from, to, limit: auditLimit.value, offset: auditOffset.value }),
    ]);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Could not load domain activity.';
  } finally {
    loading.value = false;
  }
}
function applyFilters() { timelineOffset.value = 0; requestsOffset.value = 0; securityOffset.value = 0; auditOffset.value = 0; void load(); }
function clearFilters() { search.value = ''; typeFilter.value = ''; fromInput.value = ''; toInput.value = ''; applyFilters(); }
function setTimelineLimit(value: number) { timelineLimit.value = value; timelineOffset.value = 0; void load(); }
function setTimelineOffset(value: number) { timelineOffset.value = value; void load(); }
function setRequestsLimit(value: number) { requestsLimit.value = value; requestsOffset.value = 0; void load(); }
function setRequestsOffset(value: number) { requestsOffset.value = value; void load(); }
function setSecurityLimit(value: number) { securityLimit.value = value; securityOffset.value = 0; void load(); }
function setSecurityOffset(value: number) { securityOffset.value = value; void load(); }
function setAuditLimit(value: number) { auditLimit.value = value; auditOffset.value = 0; void load(); }
function setAuditOffset(value: number) { auditOffset.value = value; void load(); }
function toEpoch(value: string) { return value ? Math.floor(new Date(value).getTime() / 1000) : undefined; }
function percent(value: number) { return `${Math.round(value * 100)}%`; }
function countryLabel(value: string) { return value && value !== 'unknown' ? value : 'Unknown'; }
function formatBytes(value: number) {
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KiB`;
  return `${(value / 1024 / 1024).toFixed(1)} MiB`;
}
function badgeClass(type: string) {
  if (type === 'error') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200';
  if (type === 'security') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200';
  if (type === 'audit') return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200';
  return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200';
}
function simpleCardHint(category: string) {
  if (category === 'waf') return 'Exploit-looking traffic stopped by WAF rules.';
  if (category === 'bot') return 'Automation that looked suspicious.';
  if (category === 'rate_limit') return 'Repeated requests slowed or blocked.';
  if (category === 'origin') return 'Requests where the origin or router failed.';
  if (category === 'ssl') return 'Certificate lifecycle events.';
  if (category === 'dns') return 'Published DNS or routing changes.';
  if (category === 'cache') return 'Cache changes or served cached traffic.';
  return 'Operational activity recorded for this domain.';
}
async function findByRequestId() {
  if (!requestIdSearch.value.trim()) return;
  requestLookupBusy.value = true;
  error.value = '';
  try {
    selected.value = await usageApi.findRequest(props.domainId, requestIdSearch.value.trim());
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Request id was not found.';
  } finally {
    requestLookupBusy.value = false;
  }
}
async function exportCurrent() {
  const from = toEpoch(fromInput.value);
  const to = toEpoch(toInput.value);
  selected.value = await usageApi.exportActivity(props.domainId, { search: search.value, type: typeFilter.value, from, to, limit: 250 });
}
</script>
