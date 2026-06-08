<template>
  <div class="space-y-5">
    <form class="panel-section" @submit.prevent="saveSettings">
      <div class="section-heading"><div><h2>SSL/TLS settings</h2><p>Control HTTPS enforcement, protocol support, and certificate renewal.</p></div><StatusBadge :status="certificates.length ? 'healthy' : 'warning'" :label="certificates.length ? 'Protected' : 'Certificate needed'" /></div>
      <div class="help-panel">
        <div class="help-item"><b>Force HTTPS</b><span>Enable after the certificate is issued so visitors are redirected from HTTP to HTTPS.</span></div>
        <div class="help-item"><b>TLS version</b><span>TLS 1.2 is the safest compatibility default. TLS 1.3 only is stricter and may reject older clients.</span></div>
        <div class="help-item"><b>Auto-renew</b><span>Keep enabled for managed certificates so renewal runs before expiry.</span></div>
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <label class="setting-row"><span><b>Force HTTPS</b><small>Redirect all HTTP traffic to HTTPS.</small></span><input v-model="settings.force_https" class="toggle" type="checkbox" /></label>
        <label class="setting-row"><span><b>Auto-renew</b><small>Renew managed certificates before expiry.</small></span><input v-model="settings.auto_renew" class="toggle" type="checkbox" /></label>
        <label><span class="field-label">Minimum TLS version</span>
          <select v-model="settings.min_tls_version" class="input">
          <option value="1.2">TLS 1.2</option>
          <option value="1.3">TLS 1.3</option>
          </select><span class="mt-1.5 block text-xs text-slate-500">TLS 1.2 provides the broadest modern compatibility.</span></label>
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
        <div class="help-item"><b>Manual import</b><span>Use only when you already have a PEM certificate and matching private key.</span></div>
      </div>
      <div class="flex flex-wrap gap-2"><button v-if="certificates.length === 0" class="button-primary" :disabled="busy" @click="requestCertificate">Request Certificate</button><button v-else class="button-primary" :disabled="busy" @click="renew">Force Renew</button><button class="button-secondary" :disabled="busy" @click="check">Check status</button><button class="button-secondary" :disabled="busy" @click="showManualImport = !showManualImport">{{ showManualImport ? 'Close manual import' : 'Import manual certificate' }}</button></div>
      <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-100">
        Customers without access to managed commercial SSL can provide their own certificate and private key here.
      </div>
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
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { sslApi } from '@/lib/api/ssl';
import type { AcmeStatus, SslCertificate } from '@/types';

const props = defineProps<{ domainId: string }>();
const certificates = ref<SslCertificate[]>([]);
const status = ref<AcmeStatus>({ progress: [], history: [] });
const busy = ref(false);
const saving = ref(false);
const saveMessage = ref('');
const saveError = ref(false);
const showManualImport = ref(false);
const manualMessage = ref('');
const manualError = ref(false);
const settings = reactive({ force_https: false, min_tls_version: '1.2' as '1.2' | '1.3', auto_renew: false });
const manual = reactive({ hostname: '', certificate_pem: '', private_key_pem: '' });
const columns = [{ key: 'hostname', label: 'Hostname' }, { key: 'status', label: 'Status' }, { key: 'issuer', label: 'Issuer' }, { key: 'expiry', label: 'Expiry' }, { key: 'last_error', label: 'Error' }];
const rows = computed(() => certificates.value.map(c => ({ ...c, expiry: c.not_after ? formatDate(c.not_after) : '' })));
let pollTimer: number | undefined;

async function load() {
  const [certs, current, acme] = await Promise.all([
    sslApi.certificates(props.domainId),
    sslApi.settings(props.domainId),
    sslApi.acmeStatus(props.domainId),
  ]);
  certificates.value = certs;
  Object.assign(settings, current);
  status.value = acme;
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
async function requestCertificate() { await runAction(() => sslApi.requestCertificate(props.domainId)); }
async function renew() { await runAction(() => sslApi.renew(props.domainId)); }
async function check() { busy.value = true; try { await sslApi.check(props.domainId); await load(); } finally { busy.value = false; } }
async function runAction(action: () => Promise<unknown>) { busy.value = true; try { await action(); await load(); } finally { busy.value = false; } }
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
function formatDate(value: number | string) { return new Date(Number(value) * 1000).toLocaleString(); }
function startPolling() {
  window.clearInterval(pollTimer);
  pollTimer = window.setInterval(async () => { status.value = await sslApi.acmeStatus(props.domainId); }, 3000);
}
watch(() => props.domainId, async () => { await load(); startPolling(); });
onMounted(async () => { await load(); startPolling(); });
onBeforeUnmount(() => window.clearInterval(pollTimer));
</script>
