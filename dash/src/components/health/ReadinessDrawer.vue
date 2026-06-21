<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50">
      <button aria-label="Close readiness drawer" class="absolute inset-0 bg-slate-950/40" @click="$emit('close')" />
      <aside class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl dark:bg-slate-950">
        <div class="mb-6 flex items-start justify-between gap-4">
          <div>
            <h2 class="text-xl font-bold text-slate-950 dark:text-white">Platform readiness</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Last checked {{ checkedAt }}</p>
          </div>
          <div class="flex gap-2">
            <button class="button-secondary text-xs" :disabled="refreshing" @click="$emit('refresh')">{{ refreshing ? 'Refreshing...' : 'Refresh' }}</button>
            <button class="button-secondary text-xs" @click="$emit('close')">Close</button>
          </div>
        </div>

        <div v-if="refreshing" class="space-y-4" role="status" aria-label="Loading platform readiness">
          <div v-for="row in 6" :key="row" class="animate-pulse rounded-xl border border-slate-200 p-4 dark:border-white/10">
            <div class="h-4 w-28 rounded bg-slate-200 dark:bg-slate-700" />
            <div class="mt-3 h-4 w-full rounded bg-slate-200 dark:bg-slate-700" />
            <div class="mt-2 h-4 w-2/3 rounded bg-slate-200 dark:bg-slate-700" />
          </div>
          <span class="sr-only">Loading readiness checks</span>
        </div>
        <div v-else-if="readiness" class="space-y-6">
          <section v-for="group in visibleGroups" :key="group.name">
            <div class="mb-3 flex items-center justify-between">
              <div>
                <h3 class="font-bold text-slate-900 dark:text-white">{{ group.title }}</h3>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ group.description }}</p>
              </div>
              <StatusBadge :status="group.readiness.status" />
            </div>
            <div class="space-y-3">
              <article v-for="check in group.readiness.checks" :key="check.key" class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                <div class="flex items-start gap-3">
                  <StatusBadge :status="check.status" />
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ check.message }}</p>
                    <p v-if="check.fix" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ check.fix }}</p>
                    <RouterLink v-if="check.link" :to="check.link" class="mt-2 inline-block text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300" @click="$emit('close')">Open fix page</RouterLink>
                  </div>
                </div>
              </article>
            </div>
          </section>
        </div>
        <p v-else class="text-sm text-slate-500">Readiness data is unavailable.</p>
      </aside>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/lib/utils/format';
import type { ReadinessResponse } from '@/types';
import StatusBadge from '@/components/ui/StatusBadge.vue';

const props = defineProps<{ open: boolean; readiness?: ReadinessResponse; refreshing?: boolean }>();
defineEmits<{ close: []; refresh: [] }>();
const checkedAt = computed(() => props.readiness?.checked_at ? formatDate(props.readiness.checked_at) : 'never');
const visibleGroups = computed(() => {
  if (!props.readiness) return [];
  const groups = [
    {
      name: 'core',
      title: 'Core infrastructure',
      description: 'Platform services required for the control plane.',
      readiness: props.readiness.core,
    },
  ];
  if (props.readiness.domain) {
    groups.push({
      name: 'domain',
      title: 'Domain warnings',
      description: 'Application and domain-specific action items.',
      readiness: props.readiness.domain,
    });
  }
  groups.push({
    name: 'edge',
    title: 'Edge',
    description: 'Edge node availability and identity checks.',
    readiness: props.readiness.edge,
  });
  return groups;
});
</script>
