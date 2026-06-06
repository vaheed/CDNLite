<template>
  <section class="space-y-6">
    <PageHeader title="Troubleshooting" description="Run consistent control-plane, edge, certificate, security, and cache checks with evidence and next steps." eyebrow="Diagnostics">
      <template #actions>
        <button class="button-primary" :disabled="running" @click="runDiagnostics">{{ running ? 'Running diagnostics…' : 'Run diagnostics' }}</button>
        <button class="button-secondary" :disabled="!hasReport" @click="copyMarkdown">Copy Markdown report</button>
        <button class="button-secondary" :disabled="!hasReport" @click="copyJson">Copy JSON report</button>
      </template>
    </PageHeader>

    <div :class="summaryClass" role="status">
      <div><p class="text-xs font-bold uppercase tracking-wider opacity-75">System summary</p><h2 class="mt-1 text-xl font-black">{{ summaryTitle }}</h2><p class="mt-1 text-sm opacity-80">{{ summaryDescription }}</p></div>
      <StatusBadge :status="summaryStatus" />
    </div>
    <div v-if="error" class="state-error" role="alert">{{ error }}</div>

    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
      <article v-for="check in checks" :key="check.key" class="card flex flex-col p-5">
        <div class="flex items-start justify-between gap-3"><h2 class="text-lg font-bold text-slate-950 dark:text-white">{{ check.title }}</h2><StatusBadge :status="check.status" /></div>
        <dl class="mt-4 space-y-3 text-sm">
          <div><dt class="font-semibold text-slate-900 dark:text-slate-100">What was checked</dt><dd class="mt-1 text-slate-600 dark:text-slate-400">{{ check.checked }}</dd></div>
          <div><dt class="font-semibold text-slate-900 dark:text-slate-100">Evidence</dt><dd class="mt-1 text-slate-600 dark:text-slate-400">{{ check.evidence }}</dd></div>
          <div><dt class="font-semibold text-slate-900 dark:text-slate-100">Suggested fix</dt><dd class="mt-1 text-slate-600 dark:text-slate-400">{{ check.fix }}</dd></div>
        </dl>
        <code v-if="check.command" class="mt-4 block overflow-x-auto rounded-lg bg-slate-950 p-3 text-xs text-cyan-200">{{ check.command }}</code>
        <details v-if="check.raw !== undefined" class="mt-4 border-t border-slate-200 pt-3 text-sm dark:border-white/10"><summary class="cursor-pointer font-semibold">Raw details</summary><pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-100">{{ JSON.stringify(check.raw, replacer, 2) }}</pre></details>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue'; import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useHealthStore } from '@/stores/health'; import { edgesApi } from '@/lib/api/edges'; import { domainsApi } from '@/lib/api/domains'; import { sslApi } from '@/lib/api/ssl'; import { purgeApi } from '@/lib/api/purge'; import { loadSecurityEventsForDomains } from '@/lib/api/securityEvents';
import { diagnosticError, heartbeatStatus, sslRisk, type DiagnosticStatus } from '@/lib/utils/diagnostics'; import type { Domain } from '@/types';
type Check = { key: string; title: string; status: DiagnosticStatus; checked: string; evidence: string; fix: string; command?: string; raw?: unknown };
const health = useHealthStore(); const running = ref(false); const error = ref('');
const checks = reactive<Check[]>([
  { key: 'api', title: 'Core API', status: 'unknown', checked: 'API reachability, /health, and /ready.', evidence: 'Diagnostics not run yet.', fix: 'Confirm the configured Core URL and browser access.', command: 'curl -i \"$VITE_CDNLITE_CORE_URL/health\"' },
  { key: 'database', title: 'Database and schema', status: 'unknown', checked: 'Core readiness evidence for database/schema dependencies.', evidence: 'Diagnostics not run yet.', fix: 'Only investigate PostgreSQL or migrations after readiness confirms a dependency failure.', command: 'php artisan migrate:status' },
  { key: 'edge', title: 'Edge network', status: 'unknown', checked: 'Edge /ready plus registered node heartbeat age.', evidence: 'Diagnostics not run yet.', fix: 'Check edge runtime reachability and agent heartbeat credentials.' },
  { key: 'security', title: 'Security events', status: 'unknown', checked: 'Recent domain security events and critical/blocking decisions.', evidence: 'Diagnostics not run yet.', fix: 'Review critical or blocking events; ordinary observations are informational.' },
  { key: 'ssl', title: 'SSL certificates', status: 'unknown', checked: 'Certificate expiry and reported certificate state.', evidence: 'Diagnostics not run yet.', fix: 'Renew expired or near-expiry certificates and inspect issuance errors.' },
  { key: 'cache', title: 'Purge queue', status: 'unknown', checked: 'Failed and pending purge requests.', evidence: 'Diagnostics not run yet.', fix: 'Inspect failed requests and verify edge configuration delivery.' },
]);
const hasReport = computed(() => checks.some((item) => item.status !== 'unknown'));
const summaryStatus = computed<DiagnosticStatus>(() => !hasReport.value ? 'unknown' : checks.some((c) => c.status === 'critical') ? 'critical' : checks.some((c) => ['warning', 'unknown'].includes(c.status)) ? 'warning' : 'healthy');
const summaryTitle = computed(() => summaryStatus.value === 'unknown' ? 'Diagnostics not run yet' : summaryStatus.value === 'critical' ? 'Critical issue detected' : summaryStatus.value === 'warning' ? 'Some checks need attention' : 'All systems healthy');
const summaryDescription = computed(() => !hasReport.value ? 'Run diagnostics to collect current evidence.' : 'Every card below shows the check, result, and an operator action.');
const summaryClass = computed(() => `flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between ${summaryTitle.value === 'Critical issue detected' ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-100' : summaryTitle.value === 'All systems healthy' ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-100' : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100'}`);
async function runDiagnostics() {
  running.value = true; error.value = '';
  try {
    const rawHealth = await health.refresh();
    update('api', health.status.apiHealthy === 'healthy' && health.status.apiReady === 'warning' ? 'warning' : health.status.apiHealthy, rawHealth.coreHealth.status === 'fulfilled' ? `API is reachable; health is ${rawHealth.coreHealth.value.ok ? 'OK' : 'not OK'} and readiness is ${health.status.apiReady}.` : `API reachability is unknown: ${diagnosticError(rawHealth.coreHealth)}`, rawHealth);
    update('database', health.status.databaseReady, rawHealth.coreReady.status === 'fulfilled' ? `Readiness response was received and is ${health.status.databaseReady}.` : `No readiness evidence was available (${diagnosticError(rawHealth.coreReady)}). Database failure is not assumed.`, rawHealth.coreReady);
    const [domainsResult, edgesResult] = await Promise.allSettled([domainsApi.list(), edgesApi.list()]);
    const domains = domainsResult.status === 'fulfilled' ? domainsResult.value : []; const edges = edgesResult.status === 'fulfilled' ? edgesResult.value : [];
    const stale = edges.filter((item) => heartbeatStatus(item) === 'warning'); const offline = edges.filter((item) => heartbeatStatus(item) === 'critical');
    const edgeStatus: DiagnosticStatus = edgesResult.status === 'rejected' || health.status.edgeReachable === 'unknown' ? 'unknown' : offline.length ? 'critical' : stale.length || health.status.edgeReachable === 'warning' ? 'warning' : 'healthy';
    update('edge', edgeStatus, edgesResult.status === 'fulfilled' ? `${edges.length} node(s): ${edges.length - stale.length - offline.length} online, ${stale.length} stale, ${offline.length} offline. Edge readiness is ${health.status.edgeReachable}.` : `Node list unavailable: ${diagnosticError(edgesResult)}`, { edgeReady: rawHealth.edgeReady, edges });
    const [security, ssl, purges] = await Promise.allSettled([loadSecurityEventsForDomains(domains), loadSsl(domains), loadPurges(domains)]);
    const events = security.status === 'fulfilled' ? security.value : []; const blocking = events.filter((item) => item.severity === 'critical' || ['block', 'blocked'].includes(String(item.decision ?? item.action).toLowerCase()));
    update('security', security.status === 'rejected' ? 'unknown' : blocking.length ? 'critical' : events.length ? 'info' : 'healthy', security.status === 'fulfilled' ? `${events.length} recent event(s); ${blocking.length} critical or blocking.` : `Events unavailable: ${diagnosticError(security)}`, security);
    const certs = ssl.status === 'fulfilled' ? ssl.value : []; const criticalCerts = certs.filter((item) => sslRisk(item) === 'critical'); const warningCerts = certs.filter((item) => sslRisk(item) === 'warning');
    update('ssl', ssl.status === 'rejected' ? 'unknown' : criticalCerts.length ? 'critical' : warningCerts.length ? 'warning' : 'healthy', ssl.status === 'fulfilled' ? `${certs.length} certificate(s); ${criticalCerts.length} critical and ${warningCerts.length} warning.` : `Certificates unavailable: ${diagnosticError(ssl)}`, ssl);
    const purgeItems = purges.status === 'fulfilled' ? purges.value : []; const failed = purgeItems.filter((item) => item.status === 'failed'); const pending = purgeItems.filter((item) => item.status === 'pending');
    update('cache', purges.status === 'rejected' ? 'unknown' : failed.length ? 'critical' : pending.length ? 'warning' : 'healthy', purges.status === 'fulfilled' ? `${purgeItems.length} request(s); ${failed.length} failed and ${pending.length} pending.` : `Purge queue unavailable: ${diagnosticError(purges)}`, purges);
  } catch (cause) { error.value = cause instanceof Error ? cause.message : 'Diagnostics failed unexpectedly.'; } finally { running.value = false; }
}
async function loadSsl(domains: Domain[]) { const items = await Promise.allSettled(domains.map((d) => sslApi.certificates(d.id))); return items.flatMap((item) => item.status === 'fulfilled' ? item.value : []); }
async function loadPurges(domains: Domain[]) { const items = await Promise.allSettled(domains.map((d) => purgeApi.list(d.id))); return items.flatMap((item) => item.status === 'fulfilled' ? item.value : []); }
function update(key: string, status: DiagnosticStatus, evidence: string, raw: unknown) { const item = checks.find((check) => check.key === key); if (item) Object.assign(item, { status, evidence, raw }); }
function replacer(_key: string, value: unknown) { return value instanceof Error ? { name: value.name, message: value.message } : value; }
function markdownReport() { return [`# CDNLite diagnostic report`, ``, `Summary: **${summaryTitle.value}**`, `Checked: ${new Date().toISOString()}`, ``, ...checks.flatMap((item) => [`## ${item.title} — ${item.status}`, `- Checked: ${item.checked}`, `- Evidence: ${item.evidence}`, `- Suggested fix: ${item.fix}`, item.command ? `- Command: \`${item.command}\`` : '', ``])].filter(Boolean).join('\n'); }
async function copyMarkdown() { await navigator.clipboard.writeText(markdownReport()); }
async function copyJson() { await navigator.clipboard.writeText(JSON.stringify({ summary: summaryTitle.value, checked_at: new Date().toISOString(), checks }, replacer, 2)); }
</script>
