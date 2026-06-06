<template>
  <section class="space-y-6">
    <div>
      <RouterLink to="/domains" class="text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300">Back to domains</RouterLink>
      <h1 class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ domain?.name ?? 'Domain' }} Analytics</h1>
      <p class="text-slate-600 dark:text-slate-400">Analytics scoped to {{ domain?.domain ?? domainId }}.</p>
    </div>
    <AnalyticsDashboard :domain-id="domainId" />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AnalyticsDashboard from '@/components/analytics/AnalyticsDashboard.vue';
import { domainsApi } from '@/lib/api/domains';
import type { Domain } from '@/types';

const route = useRoute();
const domainId = computed(() => String(route.params.domainId ?? ''));
const domain = ref<Domain | null>(null);

onMounted(async () => {
  domain.value = await domainsApi.get(domainId.value).catch(() => null);
});
</script>
