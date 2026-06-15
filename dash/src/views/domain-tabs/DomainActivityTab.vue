<template>
  <div class="space-y-6">
    <form class="panel-section grid gap-3 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
      <label><span class="field-label">Search details</span><input v-model="search" class="input" type="search" placeholder="IP, path, action, resource..." /></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
      <div class="flex items-end gap-2"><button class="button-primary flex-1">Apply</button><button type="button" class="button-secondary" @click="clearFilters">Clear</button></div>
    </form>

    <div v-if="error" class="state-error">{{ error }}</div>
    <LoadingSkeleton v-else-if="loading" />
    <template v-else>
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
import { formatDate } from '@/lib/utils/format';
import type { AuditEntry, PaginatedResult, SecurityEvent } from '@/types';

const props = defineProps<{ domainId: string }>();
const loading = ref(true);
const error = ref('');
const selected = ref<AuditEntry | SecurityEvent | null>(null);
const search = ref('');
const fromInput = ref('');
const toInput = ref('');
const securityLimit = ref(25);
const securityOffset = ref(0);
const auditLimit = ref(25);
const auditOffset = ref(0);
const security = ref<PaginatedResult<SecurityEvent>>({ items: [], total: 0, limit: 25, offset: 0 });
const audit = ref<PaginatedResult<AuditEntry>>({ items: [], total: 0, limit: 25, offset: 0 });

watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainActivity(props.domainId), queryKeys.auditLog()], load);
onMounted(load);

async function load() {
  loading.value = true;
  error.value = '';
  const from = toEpoch(fromInput.value);
  const to = toEpoch(toInput.value);
  try {
    [security.value, audit.value] = await Promise.all([
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
function clearFilters() { search.value = ''; fromInput.value = ''; toInput.value = ''; applyFilters(); }
function setSecurityLimit(value: number) { securityLimit.value = value; securityOffset.value = 0; void load(); }
function setSecurityOffset(value: number) { securityOffset.value = value; void load(); }
function setAuditLimit(value: number) { auditLimit.value = value; auditOffset.value = 0; void load(); }
function setAuditOffset(value: number) { auditOffset.value = value; void load(); }
function toEpoch(value: string) { return value ? Math.floor(new Date(value).getTime() / 1000) : undefined; }
</script>
