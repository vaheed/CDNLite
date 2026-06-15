<template>
  <section class="space-y-6">
    <PageHeader title="Job Queue" description="Monitor queued and completed system jobs across every domain." eyebrow="Operations">
      <template #actions><button class="button-secondary" :disabled="loading" @click="loadJobs">{{ loading ? 'Refreshing...' : 'Refresh jobs' }}</button></template>
    </PageHeader>

    <div class="card sticky top-[73px] z-10 grid gap-3 p-4 shadow-md sm:grid-cols-2 xl:grid-cols-5">
      <label class="sm:col-span-2 xl:col-span-1"><span class="field-label">Search jobs</span><input v-model="search" class="input" type="search" placeholder="Domain, hostname, status, error..." /></label>
      <label><span class="field-label">Status</span><select v-model="status" class="input"><option value="">All statuses</option><option v-for="item in statuses" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">Domain</span><select v-model="domain" class="input"><option value="">All domains</option><option v-for="item in domains" :key="item">{{ item }}</option></select></label>
      <label><span class="field-label">From</span><input v-model="fromInput" class="input" type="datetime-local" /></label>
      <label><span class="field-label">To</span><input v-model="toInput" class="input" type="datetime-local" /></label>
    </div>

    <div v-if="error" class="state-error" role="alert"><b>Jobs could not be loaded.</b> {{ error }} <button class="ml-2 underline" @click="loadJobs">Try again</button></div>
    <LoadingSkeleton v-else-if="loading" />
    <EmptyState v-else-if="filteredJobs.length === 0" title="No jobs found" message="No system jobs match the current filters.">
      <button v-if="hasFilters" class="button-secondary" @click="clearFilters">Clear filters</button>
    </EmptyState>
    <div v-else class="card overflow-hidden">
      <HorizontalScrollFrame class="hidden md:block" :watch-key="filteredJobs.length">
        <table class="w-full text-left text-sm">
          <thead class="table-head"><tr><th>Status</th><th>Job</th><th>Domain</th><th>Progress</th><th>Updated</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody class="divide-y divide-slate-100 dark:divide-white/5">
            <tr v-for="job in pagedJobs" :key="job.id" class="hover:bg-slate-50/80 dark:hover:bg-white/[0.03]">
              <td class="table-cell"><StatusBadge :status="badgeStatus(job.status)" :label="job.status" /></td>
              <td class="table-cell"><b class="text-slate-900 dark:text-white">SSL certificate</b><p class="mt-1 max-w-md text-xs text-slate-500">{{ job.message }}</p></td>
              <td class="table-cell">{{ job.domain_name ?? job.domain_id }}</td>
              <td class="table-cell"><div class="h-2 w-28 rounded-full bg-slate-100 dark:bg-white/10"><div class="h-2 rounded-full bg-sky-500" :style="{ width: `${job.progress_percent}%` }" /></div><span class="mt-1 block text-xs text-slate-500">{{ job.progress_percent }}%</span></td>
              <td class="table-cell whitespace-nowrap">{{ formatDate(job.updated_at) }}</td>
              <td class="table-cell text-right"><button class="button-secondary whitespace-nowrap px-3 py-1.5 text-xs" @click="selected = job">View details</button></td>
            </tr>
          </tbody>
        </table>
      </HorizontalScrollFrame>
      <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
        <article v-for="job in pagedJobs" :key="job.id" class="space-y-3 p-4">
          <div class="flex items-start justify-between gap-3"><div><StatusBadge :status="badgeStatus(job.status)" :label="job.status" /><h2 class="mt-2 font-bold text-slate-950 dark:text-white">{{ job.domain_name ?? job.domain_id }}</h2></div><span class="text-xs text-slate-500">{{ formatDate(job.updated_at) }}</span></div>
          <p class="text-sm text-slate-600 dark:text-slate-300">{{ job.message }}</p>
          <button class="button-secondary w-full" @click="selected = job">View details</button>
        </article>
      </div>
      <div class="border-t border-slate-200 p-4 dark:border-white/10">
        <PaginationControls :total="filteredJobs.length" :limit="limit" :offset="offset" @update:limit="setLimit" @update:offset="offset=$event" />
      </div>
    </div>

    <DetailsDrawer :open="Boolean(selected)" :title="selected ? `Job ${selected.id}` : 'Job details'" @close="selected = null">
      <div v-if="selected" class="space-y-6">
        <div class="flex flex-wrap items-center gap-2"><StatusBadge :status="badgeStatus(selected.status)" :label="selected.status" /><span class="text-sm text-slate-500">{{ formatDate(selected.updated_at) }}</span></div>
        <p class="text-sm leading-6 text-slate-700 dark:text-slate-300">{{ selected.message }}</p>
        <dl class="grid gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2 dark:bg-white/[0.04]">
          <div><dt class="text-slate-500">Domain</dt><dd class="mt-1 font-semibold">{{ selected.domain_name ?? selected.domain_id }}</dd></div>
          <div><dt class="text-slate-500">Progress</dt><dd class="mt-1 font-semibold">{{ selected.progress_percent }}%</dd></div>
          <div><dt class="text-slate-500">Hostnames</dt><dd class="mt-1 break-all font-semibold">{{ selected.hostnames.join(', ') || 'None' }}</dd></div>
          <div><dt class="text-slate-500">Error</dt><dd class="mt-1 break-all font-semibold">{{ selected.error_detail ?? selected.error_code ?? 'None' }}</dd></div>
        </dl>
        <details class="rounded-xl border border-slate-200 dark:border-white/10">
          <summary class="cursor-pointer p-4 font-semibold">Raw JSON</summary>
          <div class="border-t border-slate-200 p-4 dark:border-white/10"><pre class="max-h-96 overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ JSON.stringify(selected, null, 2) }}</pre></div>
        </details>
      </div>
    </DetailsDrawer>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { operationsApi } from '@/lib/api/operations';
import { formatDate } from '@/lib/utils/format';
import type { SystemJob } from '@/types';

const jobs = ref<SystemJob[]>([]); const loading = ref(true); const error = ref(''); const selected = ref<SystemJob | null>(null);
const search = ref(''); const status = ref(''); const domain = ref(''); const fromInput = ref(''); const toInput = ref(''); const limit = ref(25); const offset = ref(0);
const statuses = computed(() => unique(jobs.value.map((job) => job.status)));
const domains = computed(() => unique(jobs.value.map((job) => job.domain_name ?? job.domain_id)));
const hasFilters = computed(() => Boolean(search.value || status.value || domain.value || fromInput.value || toInput.value));
const filteredJobs = computed(() => jobs.value.filter((job) => {
  const haystack = `${job.id} ${job.domain_name ?? ''} ${job.domain_id} ${job.status} ${job.message} ${job.hostnames.join(' ')} ${job.error_code ?? ''} ${job.error_detail ?? ''}`.toLowerCase();
  const from = toEpoch(fromInput.value); const to = toEpoch(toInput.value); const updated = toEpochNumber(job.updated_at);
  return (!search.value || haystack.includes(search.value.toLowerCase())) &&
    (!status.value || job.status === status.value) &&
    (!domain.value || (job.domain_name ?? job.domain_id) === domain.value) &&
    (!from || updated >= from) &&
    (!to || updated <= to);
}).sort((a, b) => toEpochNumber(b.updated_at) - toEpochNumber(a.updated_at)));
const pagedJobs = computed(() => filteredJobs.value.slice(offset.value, offset.value + limit.value));
watch([search, status, domain, fromInput, toInput], () => { offset.value = 0; });
onMounted(loadJobs);
async function loadJobs() {
  loading.value = true; error.value = '';
  try { jobs.value = (await operationsApi.jobs({ limit: 500, offset: 0 })).items; } catch (cause) { error.value = cause instanceof Error ? cause.message : 'An unexpected request error occurred.'; } finally { loading.value = false; }
}
function badgeStatus(value: string) { if (value === 'failed') return 'critical'; if (value === 'issued') return 'healthy'; if (value === 'cancelled') return 'unknown'; return 'warning'; }
function toEpoch(value: string) { return value ? Math.floor(new Date(value).getTime() / 1000) : 0; }
function toEpochNumber(value?: number | string) { if (!value) return 0; if (typeof value === 'number') return value < 10_000_000_000 ? value : Math.floor(value / 1000); return Math.floor(new Date(value).getTime() / 1000) || 0; }
function unique(values: string[]) { return [...new Set(values)].sort(); }
function clearFilters() { search.value = ''; status.value = ''; domain.value = ''; fromInput.value = ''; toInput.value = ''; offset.value = 0; void loadJobs(); }
function setLimit(value: number) { limit.value = value; offset.value = 0; }
</script>
