<template>
  <div class="space-y-5">
    <form class="panel-section" @submit.prevent="saveSettings">
      <div class="section-heading"><div><h2>SSL/TLS settings</h2><p>Control HTTPS enforcement, protocol support, and certificate renewal.</p></div><StatusBadge :status="certificates.length ? 'healthy' : 'warning'" :label="certificates.length ? 'Protected' : 'Certificate needed'" /></div>
      <div class="help-panel">
        <div class="help-item"><b>Force HTTPS</b><span>Enable after the certificate is issued so visitors are redirected from HTTP to HTTPS.</span></div>
        <div class="help-item"><b>TLS version</b><span>TLS 1.2 accepts a wider range of modern clients. TLS 1.3 only is stricter and may reject older clients.</span></div>
        <div class="help-item"><b>Auto-renew</b><span>Keep enabled for managed certificates so renewal runs before expiry.</span></div>
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <label class="setting-row"><span><b>Force HTTPS</b><small>Redirect all HTTP traffic to HTTPS.</small></span><input v-model="settings.force_https" class="toggle" type="checkbox" /></label>
        <label class="setting-row"><span><b>Auto-renew</b><small>Renew managed certificates before expiry.</small></span><input v-model="settings.auto_renew" class="toggle" type="checkbox" /></label>
        <label><span class="field-label">Minimum TLS version</span>
          <select v-model="settings.min_tls_version" class="input">
          <option value="1.2">TLS 1.2</option>
          <option value="1.3">TLS 1.3</option>
          </select><span class="mt-1.5 block text-xs text-slate-500">TLS 1.2 supports the widest range of modern clients.</span></label>
      </div>
      <div class="mt-5 flex items-center justify-end gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
        <p v-if="saveMessage" class="mr-auto text-sm" :class="saveError ? 'text-red-600' : 'text-emerald-600'">{{ saveMessage }}</p>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save settings' }}</button>
      </div>
    </form>

    <section class="panel-section">
      <div class="section-heading"><div><h2>Certificate actions</h2><p>Issue managed certificates, verify status, or import a certificate supplied by the customer.</p></div></div>
      <div class="help-panel">
        <div class="help-item"><b>Managed certificate</b><span>Use Request Certificate when DNS points at CDNLite and you want automated issuance.</span></div>
        <div class="help-item"><b>Check status</b><span>Use after DNS changes or failed issuance to refresh challenge and certificate state.</span></div>
        <div class="help-item"><b>Job queue</b><span>Use Check job queue to list every recent SSL job and its current status.</span></div>
        <div class="help-item"><b>Manual import</b><span>Use only when you already have a PEM certificate and matching private key.</span></div>
      </div>
      <div class="flex flex-wrap gap-2"><button v-if="certificates.length === 0" class="button-primary" :disabled="busy" @click="requestCertificate">Request Certificate</button><button v-else class="button-primary" :disabled="busy" @click="renew">Force Renew</button><button class="button-secondary" :disabled="busy" @click="check">Check status</button><button class="button-secondary" :disabled="jobQueueBusy" @click="checkJobQueue">{{ jobQueueBusy ? 'Checking jobs...' : 'Check job queue' }}</button><button class="button-secondary" :disabled="busy" @click="showManualImport = !showManualImport">{{ showManualImport ? 'Close manual import' : 'Import manual certificate' }}</button></div>
      <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-100">
        Customers without access to managed commercial SSL can provide their own certificate and private key here.
      </div>
    </section>

    <section class="panel-section">
      <div class="section-heading">
        <div><h2>SSL job queue</h2><p>Recent certificate jobs and their current lifecycle status.</p></div>
        <StatusBadge :status="jobQueueStatus" :label="`${sslJobs.length} jobs`" />
      </div>
      <p v-if="jobQueueMessage" class="mb-3 text-sm" :class="jobQueueError ? 'text-rose-600' : 'text-emerald-700'">{{ jobQueueMessage }}</p>
      <EmptyState v-if="sslJobs.length === 0" title="No SSL jobs queued" message="Request a certificate to create a durable SSL job." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead class="table-head"><tr><th>Status</th><th>Progress</th><th>Hostnames</th><th>Updated</th><th>Message</th><th>Action</th></tr></thead>
          <tbody class="divide-y divide-slate-100 dark:divide-white/5">
            <tr v-for="job in sslJobs" :key="job.id">
              <td class="table-cell"><StatusBadge :status="jobBadgeStatus(job.status)" :label="jobLabel(job.status)" /></td>
              <td class="table-cell">{{ job.progress_percent }}%</td>
              <td class="table-cell font-mono text-xs">{{ job.hostnames.join(', ') || 'domain default' }}</td>
              <td class="table-cell whitespace-nowrap">{{ formatDate(job.updated_at) }}</td>
              <td class="table-cell">{{ job.error_detail || job.message }}</td>
              <td class="table-cell">
                <button
                  v-if="job.status === 'failed'"
                  class="button-secondary !px-3 !py-1.5 text-xs"
                  :disabled="busy"
                  @click="retryFailedJob(job)"
                >
                  Retry
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="activeJob" class="panel-section">
      <div class="section-heading">
        <div><h2>SSL request progress</h2><p>{{ activeJob.message }}</p></div>
        <StatusBadge :status="jobBadgeStatus(activeJob.status)" :label="jobLabel(activeJob.status)" />
      </div>
      <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
        <div class="h-full rounded-full bg-cyan-500 transition-all" :style="{ width: `${activeJob.progress_percent}%` }" />
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-500">
        <span>{{ activeJob.progress_percent }}% complete</span>
        <span>{{ activeJob.hostnames.join(', ') }}</span>
      </div>
      <p v-if="activeJob.error_detail" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ activeJob.error_detail }}</p>
    </section>

    <form v-if="showManualImport" class="panel-section" @submit.prevent="importManual">
      <div class="section-heading"><div><h2>Manual certificate</h2><p>Paste PEM material generated outside CDNLite.</p></div></div>
      <div class="help-panel">
        <div class="help-item"><b>Hostname</b><span>Use the exact covered hostname, such as example.com or www.example.com.</span></div>
        <div class="help-item"><b>Certificate PEM</b><span>Paste the full certificate chain if your issuer provides intermediates.</span></div>
        <div class="help-item"><b>Private key</b><span>Paste the private key that matches this certificate. Keep it secret outside this form.</span></div>
      </div>
      <div class="grid gap-4">
        <label><span class="field-label">Hostname</span><input v-model="manual.hostname" class="input" placeholder="www.example.com" required /><span class="field-description">This must match a name in the certificate SAN list.</span></label>
        <label><span class="field-label">Certificate PEM</span><textarea v-model="manual.certificate_pem" class="input min-h-40 py-3 font-mono" required placeholder="-----BEGIN CERTIFICATE-----" /><span class="field-description">Include BEGIN/END lines and any intermediate certificates.</span></label>
        <label><span class="field-label">Private key PEM</span><textarea v-model="manual.private_key_pem" class="input min-h-40 py-3 font-mono" required placeholder="-----BEGIN PRIVATE KEY-----" /><span class="field-description">Use the matching private key. Do not paste a CSR or public certificate here.</span></label>
      </div>
      <div class="mt-5 flex items-center justify-end gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
        <p v-if="manualMessage" class="mr-auto text-sm" :class="manualError ? 'text-red-600' : 'text-emerald-600'">{{ manualMessage }}</p>
        <button class="button-primary" :disabled="busy">Import certificate</button>
      </div>
    </form>

    <div v-if="status.progress.length" class="card p-5">
      <h3 class="font-semibold">ACME challenge status</h3>
      <div class="mt-3 space-y-2">
        <div v-for="item in status.progress" :key="item.certificate_id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3">
          <span>{{ item.hostname }}</span>
          <span class="font-medium">{{ progressLabel(item.status) }}</span>
          <span v-if="item.error" class="w-full text-sm text-red-600">{{ item.error }}</span>
        </div>
      </div>
    </div>

    <EmptyState v-if="certificates.length === 0" title="No certificates" message="Request an ACME certificate or import a manual certificate for this domain." />
    <DataTable v-else title="Certificates" :columns="columns" :rows="rows" id-key="id" />

    <div class="card p-5">
      <h3 class="font-semibold">Renewal history</h3>
      <p v-if="status.history.length === 0" class="mt-3 text-sm text-slate-500">No renewal attempts yet.</p>
      <div v-else class="mt-3 space-y-2">
        <div v-for="item in status.history" :key="item.id" class="rounded-lg border border-slate-200 p-3 text-sm">
          <div class="flex flex-wrap justify-between gap-2"><b>{{ item.hostname }}</b><span>{{ item.action }} · {{ item.status }}</span></div>
          <p class="mt-1 text-slate-500">{{ formatDate(item.started_at) }}</p>
          <p v-if="item.error" class="mt-1 text-red-600">{{ item.error }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { sslApi } from '@/lib/api/ssl';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import { useVisibilityPolling } from '@/lib/data/polling';
import { notify } from '@/lib/ui/notifications';
import type { AcmeStatus, SslCertificate, SslJob } from '@/types';

const props = defineProps<{ domainId: string }>();
const certificates = ref<SslCertificate[]>([]);
const status = ref<AcmeStatus>({ progress: [], history: [] });
const activeJob = ref<SslJob | null>(null);
const sslJobs = ref<SslJob[]>([]);
const busy = ref(false);
const jobQueueBusy = ref(false);
const jobQueueMessage = ref('');
const jobQueueError = ref(false);
const saving = ref(false);
const saveMessage = ref('');
const saveError = ref(false);
const showManualImport = ref(false);
const manualMessage = ref('');
const manualError = ref(false);
const lastJobStatuses = new Map<string, string>();
const settings = reactive({ force_https: false, min_tls_version: '1.2' as '1.2' | '1.3', auto_renew: true });
const manual = reactive({ hostname: '', certificate_pem: '', private_key_pem: '' });
const columns = [{ key: 'hostname', label: 'Hostname' }, { key: 'status', label: 'Status' }, { key: 'issuer', label: 'Issuer' }, { key: 'expiry', label: 'Expiry' }, { key: 'last_error', label: 'Error' }];
const rows = computed(() => certificates.value.map(c => ({ ...c, expiry: c.not_after ? formatDate(c.not_after) : '' })));
const jobQueueStatus = computed(() => sslJobs.value.some(job => job.status === 'failed') ? 'critical' : sslJobs.value.some(job => isActiveJob(job)) ? 'warning' : sslJobs.value.length ? 'healthy' : 'unknown');

async function load() {
  const [certs, current, acme] = await Promise.all([
    sslApi.certificates(props.domainId),
    sslApi.settings(props.domainId),
    sslApi.acmeStatus(props.domainId),
  ]);
  certificates.value = certs;
  Object.assign(settings, current);
  status.value = acme;
  sslJobs.value = acme.jobs ?? [];
  announceJobChanges(sslJobs.value);
  activeJob.value = newestActiveJob(sslJobs.value) ?? activeJob.value;
}
async function saveSettings() {
  saving.value = true;
  saveMessage.value = '';
  saveError.value = false;
  try {
    const saved = await sslApi.updateSettings(props.domainId, settings);
    Object.assign(settings, saved);
    saveMessage.value = 'SSL settings saved.';
  } catch (error) {
    saveError.value = true;
    saveMessage.value = error instanceof Error ? error.message : 'Unable to save SSL settings.';
    const persisted = await sslApi.settings(props.domainId);
    Object.assign(settings, persisted);
  } finally {
    saving.value = false;
  }
}
async function requestCertificate() {
  busy.value = true;
  try {
    const queued = await sslApi.request(props.domainId);
    activeJob.value = queued.job;
    rememberJobStatus(queued.job);
    notify({ kind: 'success', title: 'SSL request queued', message: queued.message || 'Certificate issuance is queued.' });
    await load();
  } finally {
    busy.value = false;
  }
}
async function renew() { await runAction(() => sslApi.renew(props.domainId)); }
async function check() { busy.value = true; try { await sslApi.check(props.domainId); await load(); } finally { busy.value = false; } }
async function checkJobQueue() {
  jobQueueBusy.value = true;
  jobQueueMessage.value = '';
  jobQueueError.value = false;
  try {
    const acme = await sslApi.acmeStatus(props.domainId);
    status.value = acme;
    sslJobs.value = acme.jobs ?? [];
    announceJobChanges(sslJobs.value);
    activeJob.value = newestActiveJob(sslJobs.value) ?? activeJob.value;
    jobQueueMessage.value = sslJobs.value.length ? `Checked ${sslJobs.value.length} SSL jobs.` : 'No SSL jobs are currently queued.';
  } catch (error) {
    jobQueueError.value = true;
    jobQueueMessage.value = error instanceof Error ? error.message : 'Unable to check SSL job queue.';
  } finally {
    jobQueueBusy.value = false;
  }
}
async function runAction(action: () => Promise<unknown>) { busy.value = true; try { await action(); await load(); } finally { busy.value = false; } }
async function retryFailedJob(job: SslJob) {
  busy.value = true;
  try {
    const queued = await sslApi.request(props.domainId, { hostnames: job.hostnames });
    activeJob.value = queued.job;
    rememberJobStatus(queued.job);
    notify({ kind: 'success', title: 'SSL request queued', message: `Retry queued for ${hostnamesLabel(queued.job)}.` });
    await load();
  } finally {
    busy.value = false;
  }
}
async function importManual() {
  busy.value = true;
  manualMessage.value = '';
  manualError.value = false;
  try {
    await sslApi.manualCertificate(props.domainId, manual);
    Object.assign(manual, { hostname: '', certificate_pem: '', private_key_pem: '' });
    showManualImport.value = false;
    manualMessage.value = 'Manual certificate imported.';
    await load();
  } catch (error) {
    manualError.value = true;
    manualMessage.value = error instanceof Error ? error.message : 'Unable to import manual certificate.';
  } finally {
    busy.value = false;
  }
}
function progressLabel(value: string) { return ({ pending_dns: 'Pending DNS-01', verifying: 'Verifying', issued: 'Issued', error: 'Failed', idle: 'Idle' } as Record<string, string>)[value] ?? value; }
function jobLabel(value: string) { return ({ queued: 'Queued', checking_dns: 'Checking DNS', creating_order: 'Creating order', validating_challenge: 'Validating challenge', issuing: 'Issuing', installing: 'Installing', issued: 'Issued', failed: 'Failed', cancelled: 'Cancelled' } as Record<string, string>)[value] ?? value; }
function jobBadgeStatus(value: string) { return value === 'issued' ? 'healthy' : value === 'failed' ? 'critical' : 'warning'; }
function isActiveJob(job: SslJob) { return ['queued', 'checking_dns', 'creating_order', 'validating_challenge', 'issuing', 'installing'].includes(job.status); }
function newestActiveJob(jobs: SslJob[]) { return jobs.find(isActiveJob) ?? null; }
function formatDate(value: number | string) { return new Date(Number(value) * 1000).toLocaleString(); }
function hostnamesLabel(job: SslJob) { return job.hostnames.length ? job.hostnames.join(', ') : 'the domain default hostnames'; }
function rememberJobStatus(job: SslJob) { lastJobStatuses.set(job.id, job.status); }
function announceJobChanges(jobs: SslJob[]) {
  for (const job of jobs) {
    const previous = lastJobStatuses.get(job.id);
    if (!previous) {
      rememberJobStatus(job);
      continue;
    }
    if (previous === job.status) continue;
    rememberJobStatus(job);
    announceJobStatus(job);
  }
}
function announceJobStatus(job: SslJob) {
  if (job.status === 'validating_challenge' || job.status === 'checking_dns') {
    notify({ kind: 'info', title: 'DNS validation in progress', message: job.message || `Checking ${hostnamesLabel(job)}.` });
    return;
  }
  if (job.status === 'issued') {
    notify({ kind: 'success', title: 'Certificate issued', message: `SSL is ready for ${hostnamesLabel(job)}.` });
    return;
  }
  if (job.status === 'failed') {
    notify({ kind: 'error', title: 'SSL failed', message: job.error_detail || job.message || 'Certificate issuance failed.' }, 8000);
  }
}
async function pollSslProgress() {
  if (activeJob.value && isActiveJob(activeJob.value)) {
    const current = await sslApi.job(props.domainId, activeJob.value.id);
    announceJobChanges([current]);
    activeJob.value = current;
    if (!isActiveJob(activeJob.value)) {
      await load();
    }
    return;
  }
  const next = await sslApi.acmeStatus(props.domainId);
  status.value = next;
  sslJobs.value = next.jobs ?? [];
  announceJobChanges(sslJobs.value);
  activeJob.value = newestActiveJob(sslJobs.value);
}
watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainSsl(props.domainId)], load);
useVisibilityPolling(pollSslProgress, 3000, {
  enabled: () => Boolean(activeJob.value && isActiveJob(activeJob.value)),
});
onMounted(load);
</script>
