<template>
  <div class="space-y-6">
    <form class="panel-section grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="applyFilters">
      <label><span class="field-label">Search details</span><input v-model="search" class="input" type="search" placeholder="Request ID, path, action, origin..." /></label>
      <label><span class="field-label">Event type</span><select v-model="typeFilter" class="input"><option value="">All activity</option><option value="request">Requests</option><option value="error">Errors</option><option value="audit">Changes</option><option value="security">Security</option></select></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
      <div class="flex items-end gap-2"><button class="button-primary flex-1">Apply</button><button type="button" class="button-secondary" @click="clearFilters">Clear</button><button type="button" class="button-secondary" @click="exportCurrent">Export JSON</button></div>
    </form>
    <form class="panel-section flex flex-col gap-3 md:flex-row md:items-end" @submit.prevent="findByRequestId">
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

      <section class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Activity timeline</h2><p>Requests, origin errors, DNS/SSL changes, and security events in one stream.</p></div></div>
        <EmptyState v-if="!timeline.items.length" title="No timeline events" message="No domain activity matches the current filters." />
        <div v-else class="space-y-3">
          <button v-for="item in timeline.items" :key="item.id" class="w-full rounded-lg border border-slate-200 bg-white p-4 text-left transition hover:border-sky-300 dark:border-white/10 dark:bg-white/5" @click="selected=item">
            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(item.type)">{{ item.type }}</span>
              <span class="text-sm text-slate-500">{{ formatDate(item.ts) }}</span>
              <span v-if="item.request_id" class="font-mono text-xs text-slate-500">{{ item.request_id }}</span>
            </div>
            <p class="mt-2 font-semibold text-slate-950 dark:text-white">{{ item.title }}</p>
            <p v-if="item.summary" class="mt-1 text-sm text-slate-500">{{ item.summary }}</p>
          </button>
        </div>
      </section>

      <section class="grid gap-4 xl:grid-cols-3">
        <div class="panel-section space-y-3">
          <h2 class="text-base font-semibold">Top paths</h2>
          <p v-for="row in summary?.top_paths || []" :key="row.value" class="flex justify-between gap-3 text-sm"><span class="truncate font-mono">{{ row.value }}</span><b>{{ row.count }}</b></p>
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

      <section class="panel-section space-y-4">
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

      <section class="panel-section space-y-4">
        <div class="section-heading"><div><h2>Recent edge requests</h2><p>Request, cache, router, and origin forwarding details captured from edge metrics.</p></div></div>
        <EmptyState v-if="!requests.length" title="No request details" message="No edge request metrics have been ingested for this domain yet." />
        <HorizontalScrollFrame v-else :watch-key="requests.length">
          <table class="w-full min-w-[960px] text-left text-sm">
            <thead class="table-head"><tr><th>Time</th><th>Request</th><th>Status</th><th>Cache</th><th>Origin</th><th>Upstream</th><th>Request ID</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
              <tr v-for="request in requests" :key="request.id">
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
      </section>

      <section class="panel-section space-y-4">
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

      <section class="panel-section space-y-4">
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
import { onMounted, ref, watch } from 'vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { auditLogApi } from '@/lib/api/auditLog';
import { securityEventsApi } from '@/lib/api/securityEvents';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import { useVisibilityPolling } from '@/lib/data/polling';
import { usageApi } from '@/lib/api/usage';
import { formatDate } from '@/lib/utils/format';
import type { ActivityExport, ActivitySummary, ActivityTimeline, ActivityTimelineItem, AuditEntry, PaginatedResult, RequestActivity, SecurityEvent } from '@/types';

const props = defineProps<{ domainId: string }>();
const loading = ref(true);
const error = ref('');
const selected = ref<AuditEntry | RequestActivity | SecurityEvent | ActivityTimelineItem | ActivityExport | null>(null);
const search = ref('');
const typeFilter = ref('');
const requestIdSearch = ref('');
const requestLookupBusy = ref(false);
const fromInput = ref('');
const toInput = ref('');
const securityLimit = ref(25);
const securityOffset = ref(0);
const auditLimit = ref(25);
const auditOffset = ref(0);
const security = ref<PaginatedResult<SecurityEvent>>({ items: [], total: 0, limit: 25, offset: 0 });
const audit = ref<PaginatedResult<AuditEntry>>({ items: [], total: 0, limit: 25, offset: 0 });
const requests = ref<RequestActivity[]>([]);
const summary = ref<ActivitySummary | null>(null);
const timeline = ref<ActivityTimeline>({ items: [], total: 0, limit: 100, cursor: null });

watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainActivity(props.domainId), queryKeys.auditLog()], load);
useVisibilityPolling(load, 30000);
onMounted(load);

async function load() {
  loading.value = true;
  error.value = '';
  const from = toEpoch(fromInput.value);
  const to = toEpoch(toInput.value);
  try {
    [summary.value, timeline.value, requests.value, security.value, audit.value] = await Promise.all([
      usageApi.activitySummary(props.domainId, { from, to }),
      usageApi.activityTimeline(props.domainId, { search: search.value, type: typeFilter.value, from, to, limit: 100 }),
      usageApi.recentRequests(props.domainId, { limit: 50 }),
      securityEventsApi.list({ domain_id: props.domainId, search: search.value, from, to, limit: securityLimit.value, offset: securityOffset.value }),
      auditLogApi.list({ domain_id: props.domainId, search: search.value, from, to, limit: auditLimit.value, offset: auditOffset.value }),
    ]);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Could not load domain activity.';
  } finally {
    loading.value = false;
  }
}
function applyFilters() { securityOffset.value = 0; auditOffset.value = 0; void load(); }
function clearFilters() { search.value = ''; typeFilter.value = ''; fromInput.value = ''; toInput.value = ''; applyFilters(); }
function setSecurityLimit(value: number) { securityLimit.value = value; securityOffset.value = 0; void load(); }
function setSecurityOffset(value: number) { securityOffset.value = value; void load(); }
function setAuditLimit(value: number) { auditLimit.value = value; auditOffset.value = 0; void load(); }
function setAuditOffset(value: number) { auditOffset.value = value; void load(); }
function toEpoch(value: string) { return value ? Math.floor(new Date(value).getTime() / 1000) : undefined; }
function percent(value: number) { return `${Math.round(value * 100)}%`; }
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
