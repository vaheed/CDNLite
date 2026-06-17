<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div><h2>Origins</h2><p>Monitor DNS-linked and manually added origin health for this domain.</p></div>
      <button class="button-primary" @click="startCreate"><Plus class="h-4 w-4" /> Add origin</button>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <form v-if="editing" class="panel-section" @submit.prevent="save">
      <div class="section-heading">
        <div><h2>{{ editingId ? 'Edit origin' : 'Add origin' }}</h2><p>The edge chooses from enabled origins and avoids unhealthy ones.</p></div>
        <button type="button" class="icon-button" aria-label="Close editor" @click="editing = false"><X class="h-4 w-4" /></button>
      </div>
      <div class="help-panel">
        <div class="help-item"><b>Host examples</b><span>Use origin.example.com or 192.0.2.10. Do not include http://, https://, or a path.</span></div>
        <div class="help-item"><b>Independent origins</b><span>Add as many backend addresses as you need for the same site. The edge balances between healthy ones automatically.</span></div>
        <div class="help-item"><b>Health checks</b><span>Use a lightweight path such as /health that returns 200 without expensive work.</span></div>
      </div>
      <div class="grid gap-4 md:grid-cols-3">
        <label><span class="field-label">Protocol</span><select v-model="originProtocol" class="input"><option value="http">HTTP :80</option><option value="https">HTTPS :443</option></select><span class="field-description">Choose how the edge connects to this origin.</span></label>
        <label class="md:col-span-2"><span class="field-label">Host</span><input v-model="form.host" class="input" placeholder="origin.example.com" /><span class="field-description">Hostname or IP only, without protocol or path.</span></label>
        <label><span class="field-label">Host header</span><input v-model="form.host_header" class="input" placeholder="origin.example.com" /><span class="field-description">Leave blank to send the origin host.</span></label>
        <label><span class="field-label">SNI</span><input v-model="form.sni" class="input" placeholder="origin.example.com" /><span class="field-description">Leave blank to use the origin host for TLS SNI.</span></label>
        <label><span class="field-label">TLS verify</span><select v-model="form.tls_verify" class="input"><option value="ignore">Off</option><option value="verify">On</option></select><span class="field-description">Off is the safe default for plain HTTP and self-signed HTTPS origins.</span></label>
        <label><span class="field-label">Health path</span><input v-model="form.health_check_path" class="input" placeholder="/health" /><span class="field-description">Must begin with / and should be cheap for the origin to serve.</span></label>
        <label><span class="field-label">Timeout</span><input v-model.number="form.health_check_timeout_seconds" class="input" type="number" min="1" max="60" /><span class="field-description">Use 5 seconds for most origins; raise only for slower backends.</span></label>
      </div>
      <div class="mt-5 grid gap-3 md:grid-cols-2">
        <label class="setting-row">
          <span><b>Enabled</b><small>Disabled origins are ignored by the edge.</small></span>
          <input v-model="form.enabled" class="toggle" type="checkbox" />
        </label>
        <label class="setting-row">
          <span><b>Preserve CDN host</b><small>Send the visitor Host header instead of the origin host header.</small></span>
          <input v-model="form.preserve_host" class="toggle" type="checkbox" />
        </label>
      </div>
      <p v-if="error" class="state-error mt-4">{{ error }}</p>
      <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
        <button type="button" class="button-secondary" @click="editing = false">Cancel</button>
        <button class="button-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save origin' }}</button>
      </div>
    </form>

    <EmptyState v-if="!loading && rows.length === 0" title="No origins yet" message="Create a proxied DNS record or add an origin manually." />
    <section v-else-if="rows.length" class="space-y-3">
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="metric-panel">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Origins</p>
          <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ originStats.total }}</p>
        </div>
        <div class="metric-panel">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Healthy</p>
          <p class="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-200">{{ originStats.healthy }}</p>
        </div>
        <div class="metric-panel">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Enabled</p>
          <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ originStats.enabled }}</p>
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-900/70">
        <div class="flex flex-col gap-1 border-b border-slate-200 px-4 py-4 dark:border-white/10 sm:px-5">
          <h3 class="font-semibold tracking-tight text-slate-950 dark:text-white">Origin route list</h3>
          <p class="text-sm text-slate-500 dark:text-slate-400">Readable upstream list with health, routing, and quick actions.</p>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-white/[0.06]">
          <article v-for="row in rows" :key="row.id" class="relative grid gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(260px,.8fr)_minmax(210px,auto)] xl:items-center">
            <div :class="originAccentClass(row)" class="absolute inset-y-4 left-0 w-1 rounded-r-full" aria-hidden="true" />
            <div class="min-w-0 pl-3">
              <div class="flex flex-wrap items-center gap-2">
                <span :class="originDotClass(row)" class="h-2.5 w-2.5 rounded-full" aria-hidden="true" />
                <StatusBadge status="info" label="Origin" />
                <StatusBadge :status="row.source === 'dns_record' ? 'info' : 'unknown'" :label="sourceLabel(row)" />
                <StatusBadge :status="row.enabled ? 'healthy' : 'unknown'" :label="row.enabled ? 'Enabled' : 'Disabled'" />
              </div>
              <div class="mt-3 flex min-w-0 items-center gap-2">
                <Server class="h-4 w-4 shrink-0 text-slate-400" />
                <p class="truncate font-mono text-base font-semibold text-slate-950 dark:text-white" :title="originUrl(row)">
                  {{ originUrl(row) }}
                </p>
              </div>
              <p class="mt-2 truncate text-sm text-slate-500" :title="originDetails(row)">{{ originDetails(row) }}</p>
              <p v-if="row.dns_record_id" class="mt-2 truncate rounded-md bg-slate-50 px-2 py-1 font-mono text-[11px] text-slate-500 dark:bg-white/[0.04]" :title="row.dns_record_id">
                DNS {{ row.dns_record_id }}
              </p>
            </div>

            <dl class="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/[0.035] sm:grid-cols-3 xl:grid-cols-1">
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Health</dt>
                <dd class="mt-1"><StatusBadge :status="healthSeverity(String(row.health_status))" :label="healthLabel(row.health_status)" /></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last checked</dt>
                <dd class="mt-1 text-slate-700 dark:text-slate-300">{{ row.last_check_at ? formatTime(Number(row.last_check_at)) : 'Not checked' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">TLS</dt>
                <dd class="mt-1 text-slate-700 dark:text-slate-300">{{ tlsLabel(row) }}</dd>
              </div>
            </dl>

            <div class="grid grid-cols-3 gap-2 xl:flex xl:flex-col">
              <button class="button-secondary justify-center px-3 py-2 text-xs" @click="check(row)"><RefreshCw class="h-3.5 w-3.5" /> Check</button>
              <button class="button-secondary justify-center px-3 py-2 text-xs" @click="startEdit(row)"><Pencil class="h-3.5 w-3.5" /> Edit</button>
              <button class="button-secondary justify-center px-3 py-2 text-xs" @click="toggle(row)">{{ row.enabled ? 'Disable' : 'Enable' }}</button>
              <ConfirmDangerButton class="col-span-3 justify-center px-3 py-2 text-xs xl:col-span-1" confirm-text="Delete this origin?" @confirm="remove(row)"><Trash2 class="h-3.5 w-3.5" /> Delete</ConfirmDangerButton>
            </div>
          </article>
        </div>
      </div>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Pencil, Plus, RefreshCw, Server, Trash2, X } from 'lucide-vue-next';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { originsApi } from '@/lib/api/origins';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { DomainOrigin, Severity } from '@/types';

const props = defineProps<{ domainId: string }>();
const rows = ref<DomainOrigin[]>([]);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const editingId = ref('');
const message = ref('');
const error = ref('');
const form = reactive({
  scheme: 'http' as 'http' | 'https',
  host: '',
  port: 80,
  host_header: '',
  sni: '',
  tls_verify: 'ignore' as 'verify' | 'ignore',
  preserve_host: false,
  health_check_path: '/',
  health_check_interval_seconds: 30,
  health_check_timeout_seconds: 5,
  enabled: true,
});
const originStats = computed(() => ({
  total: rows.value.length,
  healthy: rows.value.filter((row) => row.health_status === 'healthy').length,
  enabled: rows.value.filter((row) => row.enabled).length,
}));
const originProtocol = computed<'http' | 'https'>({
  get: () => form.scheme,
  set: (value) => {
    form.scheme = value;
    form.port = value === 'https' ? 443 : 80;
  },
});

function reset() { Object.assign(form, { scheme: 'http', host: '', port: 80, host_header: '', sni: '', tls_verify: 'ignore', preserve_host: false, health_check_path: '/', health_check_interval_seconds: 30, health_check_timeout_seconds: 5, enabled: true }); error.value = ''; }
function healthSeverity(status: string): Severity { if (status === 'healthy') return 'healthy'; if (status === 'unhealthy') return 'critical'; return 'unknown'; }
function healthLabel(status: unknown) { const value = String(status ?? 'unknown'); return value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' '); }
function originUrl(row: DomainOrigin) { return `${row.scheme}://${row.host}:${row.port}`; }
function sourceLabel(row: DomainOrigin) { if (row.source === 'dns_record') return 'DNS record'; if (row.source === 'manual') return 'Manual'; return String(row.source ?? 'Manual'); }
function tlsLabel(row: DomainOrigin) { return row.scheme === 'https' ? `HTTPS, ${row.tls_verify === 'ignore' ? 'not verified' : 'verified'}` : 'HTTP'; }
function originAccentClass(row: DomainOrigin) {
  if (!row.enabled) return 'bg-slate-300 dark:bg-slate-600';
  if (row.health_status === 'healthy') return 'bg-emerald-500';
  if (row.health_status === 'unhealthy') return 'bg-red-500';
  return 'bg-amber-400';
}
function originDotClass(row: DomainOrigin) {
  if (!row.enabled) return 'bg-slate-400';
  if (row.health_status === 'healthy') return 'bg-emerald-500';
  if (row.health_status === 'unhealthy') return 'bg-red-500';
  return 'bg-amber-400';
}
function originDetails(row: DomainOrigin) {
  const details = [`Health ${row.health_check_path || '/'}`];
  if (row.host_header) details.push(`Host ${row.host_header}`);
  if (row.sni) details.push(`SNI ${row.sni}`);
  if (row.preserve_host) details.push('Preserves CDN host');
  return details.join(' | ');
}
function formatTime(value: number) { return new Date(value * 1000).toLocaleString(); }
async function load() { loading.value = true; try { rows.value = await originsApi.list(props.domainId); } finally { loading.value = false; } }
function startCreate() { editingId.value = ''; reset(); editing.value = true; }
function startEdit(row: Record<string, unknown>) { editingId.value = String(row.id); Object.assign(form, { scheme: String(row.scheme ?? 'http'), host: String(row.host ?? ''), port: Number(row.port ?? 80), host_header: String(row.host_header ?? ''), sni: String(row.sni ?? ''), tls_verify: String(row.tls_verify ?? 'ignore'), preserve_host: Boolean(row.preserve_host), health_check_path: String(row.health_check_path ?? '/'), health_check_interval_seconds: Number(row.health_check_interval_seconds ?? 30), health_check_timeout_seconds: Number(row.health_check_timeout_seconds ?? 5), enabled: Boolean(row.enabled) }); error.value = ''; editing.value = true; }
async function save() {
  error.value = '';
  const host = form.host.trim();
  if (!host) { error.value = 'Origin host is required.'; return; }
  if (/^https?:\/\//i.test(host) || host.includes('/')) { error.value = 'Enter a hostname or IP only, without protocol or path.'; return; }
  form.port = form.scheme === 'https' ? 443 : 80;
  if (form.scheme === 'http') {
    form.tls_verify = 'ignore';
  }
  if (!form.health_check_path.startsWith('/')) { error.value = 'Health check path must start with /.'; return; }
  saving.value = true;
  try {
    editingId.value ? await originsApi.update(props.domainId, editingId.value, form) : await originsApi.create(props.domainId, form);
    editing.value = false;
    message.value = 'Origin saved.';
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to save origin.';
  } finally { saving.value = false; }
}
async function toggle(row: Record<string, unknown>) { await originsApi.update(props.domainId, String(row.id), { enabled: !row.enabled }); await load(); }
async function check(row: Record<string, unknown>) { await originsApi.check(props.domainId, String(row.id)); message.value = 'Origin health checked.'; await load(); }
async function remove(row: Record<string, unknown>) { await originsApi.remove(props.domainId, String(row.id)); message.value = 'Origin deleted.'; await load(); }
watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domainOrigins(props.domainId)], load);
onMounted(load);
</script>
