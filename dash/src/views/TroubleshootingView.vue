<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-3xl font-black text-slate-950 dark:text-white">Troubleshooting</h1>
        <p class="text-slate-600 dark:text-slate-400">Run control-plane, edge, DNS, SSL, and runtime checks from one place.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="button-primary" :disabled="running" @click="runDiagnostics">{{ running ? 'Running...' : 'Run diagnostics' }}</button>
        <button class="button-secondary" :disabled="!reportText" @click="copyReport">Copy report</button>
      </div>
    </div>

    <div v-if="error" role="alert" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ error }}</div>

    <div class="grid gap-4 xl:grid-cols-3">
      <div v-for="check in checks" :key="check.key" class="card p-5">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-lg font-bold text-slate-950 dark:text-white">{{ check.title }}</h2>
          <StatusBadge :status="check.status" />
        </div>
        <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ check.summary }}</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400"><b>Suggested fix:</b> {{ check.fix }}</p>
      </div>
    </div>

    <div class="card p-5">
      <h2 class="text-lg font-bold text-slate-950 dark:text-white">Diagnostic Report</h2>
      <pre class="mt-4 max-h-[520px] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ reportText || 'Run diagnostics to generate a report.' }}</pre>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { healthApi } from '@/lib/api/health';
import { edgesApi } from '@/lib/api/edges';
import { sitesApi } from '@/lib/api/sites';
import { sslApi } from '@/lib/api/ssl';
import { purgeApi } from '@/lib/api/purge';
import { loadSecurityEventsForSites } from '@/lib/api/securityEvents';
import { heartbeatStatus, sslRisk } from '@/lib/utils/diagnostics';
import type { Site } from '@/types';

type CheckStatus = 'unknown' | 'healthy' | 'warning' | 'critical' | 'info';
type Check = { key: string; title: string; status: CheckStatus; summary: string; fix: string; result?: unknown };

const running = ref(false);
const error = ref('');
const checks = reactive<Check[]>([
  { key: 'core', title: 'Core API', status: 'unknown', summary: 'Not checked yet.', fix: 'Verify core /health, /ready, and VITE_CDNLITE_CORE_URL.' },
  { key: 'edge', title: 'Edge Health', status: 'unknown', summary: 'Not checked yet.', fix: 'Check edge /ready, edge-agent token, and edge node heartbeats.' },
  { key: 'database', title: 'Database/schema', status: 'unknown', summary: 'Not checked yet.', fix: 'If core ready fails, run migrations and inspect PostgreSQL connectivity.' },
  { key: 'security', title: 'Security Events', status: 'unknown', summary: 'Not checked yet.', fix: 'Review WAF/rate-limit rules and recent event payloads.' },
  { key: 'ssl', title: 'SSL Certificates', status: 'unknown', summary: 'Not checked yet.', fix: 'Renew/import certificates with warning or critical expiry.' },
  { key: 'cache', title: 'Cache Purges', status: 'unknown', summary: 'Not checked yet.', fix: 'Inspect stuck purge requests and edge config refresh.' },
]);

const reportText = computed(() => checks.some((check) => check.status !== 'unknown') ? JSON.stringify(checks, null, 2) : '');

async function runDiagnostics() {
  running.value = true;
  error.value = '';
  try {
    const [coreHealth, coreReady, edgeReady, sitesResult, edgesResult] = await Promise.allSettled([
      healthApi.coreHealth(),
      healthApi.coreReady(),
      healthApi.edgeReady(),
      sitesApi.list(),
      edgesApi.list(),
    ]);
    const sites = sitesResult.status === 'fulfilled' ? sitesResult.value : ([] as Site[]);
    const edges = edgesResult.status === 'fulfilled' ? edgesResult.value : [];
    update('core', coreHealth.status === 'fulfilled' && coreReady.status === 'fulfilled' && coreHealth.value.ok && coreReady.value.ok ? 'healthy' : 'critical', `Health: ${resultSummary(coreHealth)}. Ready: ${resultSummary(coreReady)}.`, { coreHealth, coreReady });
    update('database', coreReady.status === 'fulfilled' && coreReady.value.ok ? 'healthy' : 'critical', coreReady.status === 'fulfilled' ? 'Core ready check passed.' : 'Core ready failed; database or schema may be unavailable.', coreReady);
    const staleEdges = edges.filter((edge) => heartbeatStatus(edge) !== 'ok');
    update('edge', edgeReady.status === 'fulfilled' && edgeReady.value.ok && staleEdges.length === 0 ? 'healthy' : 'warning', `${edges.length} edge node(s), ${staleEdges.length} stale/offline. Edge ready: ${resultSummary(edgeReady)}.`, { edgeReady, edges });

    const [security, ssl, purges] = await Promise.allSettled([
      loadSecurityEventsForSites(sites),
      loadSsl(sites),
      loadPurges(sites),
    ]);
    const securityEvents = security.status === 'fulfilled' ? security.value : [];
    const certs = ssl.status === 'fulfilled' ? ssl.value : [];
    const purgeItems = purges.status === 'fulfilled' ? purges.value : [];
    const sslRisks = certs.filter((cert) => ['critical', 'warning'].includes(sslRisk(cert)));
    const stuckPurges = purgeItems.filter((purge) => ['failed', 'pending'].includes(String(purge.status ?? '').toLowerCase()));
    update('security', security.status === 'fulfilled' ? (securityEvents.length ? 'info' : 'healthy') : 'warning', `${securityEvents.length} recent security event(s) found.`, security);
    update('ssl', ssl.status === 'fulfilled' && sslRisks.length === 0 ? 'healthy' : 'warning', `${certs.length} certificate(s), ${sslRisks.length} warning/critical.`, certs);
    update('cache', purges.status === 'fulfilled' && stuckPurges.length === 0 ? 'healthy' : 'warning', `${purgeItems.length} purge request(s), ${stuckPurges.length} failed or pending.`, purgeItems);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Diagnostics failed.';
  } finally {
    running.value = false;
  }
}

async function loadSsl(sites: Site[]) {
  const results = await Promise.allSettled(sites.map((site) => sslApi.certificates(site.id)));
  return results.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
}

async function loadPurges(sites: Site[]) {
  const results = await Promise.allSettled(sites.map((site) => purgeApi.list(site.id)));
  return results.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
}

function update(key: string, status: CheckStatus, summary: string, result: unknown) {
  const check = checks.find((item) => item.key === key);
  if (check) Object.assign(check, { status, summary, result });
}

function resultSummary(result: PromiseSettledResult<{ ok?: boolean }>) {
  return result.status === 'fulfilled' ? (result.value.ok ? 'ok' : 'not ok') : result.reason instanceof Error ? result.reason.message : 'failed';
}

async function copyReport() {
  if (reportText.value) await navigator.clipboard.writeText(reportText.value);
}
</script>
