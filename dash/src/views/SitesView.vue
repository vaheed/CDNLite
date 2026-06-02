<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-black text-slate-950 dark:text-white">Sites</h1><p class="text-slate-600 dark:text-slate-400">Full lifecycle management for CDN sites, origins, and proxy state.</p></div>
    <div class="flex justify-end">
      <button type="button" class="button-primary" @click="startCreate">Add site</button>
    </div>
    <form v-if="showForm" class="card grid gap-4 p-4 sm:p-5 xl:grid-cols-2" @submit.prevent="saveSite">
      <div v-if="formError" role="alert" class="xl:col-span-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ formError }}</div>
      <TextInput v-model="form.name" :help="{ ...help.name, error: fieldErrors.name }" />
      <TextInput v-model="form.domain" :help="{ ...help.domain, error: fieldErrors.domain }" />
      <label class="space-y-2">
        <span class="flex items-center gap-1 text-sm font-semibold text-slate-800 dark:text-slate-100">Origin scheme <span class="text-red-600 dark:text-red-400">*</span></span>
        <select v-model="form.origin_scheme" class="input">
          <option value="http">HTTP</option>
          <option value="https">HTTPS</option>
        </select>
        <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">{{ help.origin_scheme.what }} Example: {{ help.origin_scheme.example }}</p>
      </label>
      <TextInput v-model="form.origin_host" :help="{ ...help.origin_host, error: fieldErrors.origin_host }" />
      <TextInput v-model="form.origin_port" type="number" :help="{ ...help.origin_port, error: fieldErrors.origin_port }" />
      <label class="space-y-2">
        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Status</span>
        <select v-model="form.status" class="input">
          <option value="active">Active</option>
          <option value="disabled">Disabled</option>
        </select>
        <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">Controls whether the site is active in generated configuration.</p>
      </label>
      <JsonEditorField v-model="geoOrigins" :help="help.geo_origins" />
      <TextInput v-model="form.origin_shield_header_name" :help="help.origin_shield_header_name" />
      <TextInput v-model="form.origin_shield_secret" :help="{ ...help.origin_shield_secret, error: fieldErrors.origin_shield_secret }" />
      <div class="xl:col-span-2 flex flex-wrap justify-end gap-2">
        <button v-if="editingId" type="button" class="button-secondary" @click="resetForm">Cancel edit</button>
        <button class="button-primary" :disabled="saving">{{ editingId ? 'Save changes' : 'Create site' }}</button>
      </div>
    </form>
    <DataTable title="Sites" subtitle="Search, sort, paginate, and copy IDs." :rows="siteRows" :columns="columns">
      <template #id="{ value }"><div class="flex items-center gap-2"><code class="text-xs">{{ value }}</code><CopyButton :text="String(value)" label="Copy ID" /></div></template>
      <template #status="{ row }"><div class="flex items-center gap-2"><StatusBadge :status="String(row.status ?? 'active')" /><button class="button-secondary px-2 py-1 text-xs" @click="toggleStatus(row)">{{ row.status === 'disabled' ? 'Activate' : 'Disable' }}</button></div></template>
      <template #proxy_enabled="{ row }"><div class="flex items-center gap-2"><StatusBadge :status="row.proxy_enabled ? 'enabled' : 'disabled'" :label="row.proxy_enabled ? 'Proxy on' : 'Proxy off'" /><button class="button-secondary px-2 py-1 text-xs" @click="toggleProxy(row)">{{ row.proxy_enabled ? 'Disable' : 'Enable' }}</button></div></template>
      <template #actions="{ row }"><div class="flex flex-wrap gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="editSite(row)">Edit</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this site?" @confirm="deleteSite(String(row.id))">Delete</ConfirmDangerButton></div></template>
    </DataTable>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { z } from 'zod';
import TextInput from '@/components/forms/TextInput.vue';
import JsonEditorField from '@/components/forms/JsonEditorField.vue';
import DataTable from '@/components/ui/DataTable.vue';
import CopyButton from '@/components/ui/CopyButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import { sitesApi } from '@/lib/api/sites';
import { CdnLiteApiError } from '@/lib/api/client';
import type { CreateSiteInput, Site, UpdateSiteInput } from '@/types';
const sites = ref<Site[]>([]); const saving = ref(false); const editingId = ref(''); const showForm = ref(false); const formError = ref(''); const fieldErrors = reactive<Record<string, string>>({});
const geoOrigins = ref('{\n  "eu": "https://eu-origin.example.com",\n  "us": "https://us-origin.example.com"\n}');
const form = reactive({ name: '', domain: '', origin_scheme: 'http', origin_host: '', origin_port: 80, proxy_enabled: true, status: 'active', origin_shield_header_name: '', origin_shield_secret: '' });
const siteSchema = z.object({ name: z.string().min(1, 'Site name is required.'), domain: z.string().min(1, 'Domain is required.'), origin_scheme: z.string().min(1, 'Origin scheme is required.'), origin_host: z.string().min(1, 'Origin host is required.'), origin_port: z.coerce.number().int().positive('Origin port must be a positive integer.') });
const help = {
  name: { label: 'Name', what: 'Human-readable site name shown in the admin.', works: 'Does not affect routing; used for management.', example: 'Main Website', required: true },
  domain: { label: 'Domain', what: 'Hostname served by the CDN.', works: 'Edge matches incoming Host header to this domain.', example: 'example.com', required: true },
  origin_scheme: { label: 'Origin scheme', what: 'Protocol used when the edge connects to origin.', works: 'Usually http for Docker/internal origin, https for public origin.', example: 'https', required: true },
  origin_host: { label: 'Origin host', what: 'Host/IP of the upstream origin server.', works: 'Edge proxies cache misses to this origin.', example: 'origin.example.com', required: true },
  origin_port: { label: 'Origin port', what: 'Port of the upstream origin server.', works: 'Combined with scheme and host to build upstream URL.', example: '443', required: true },
  geo_origins: { label: 'Geo origins', what: 'Optional region-specific origins as JSON.', works: 'Edge can route by geo/region when config supports it.', example: '{ "eu": "https://eu-origin.example.com" }' },
  origin_shield_header_name: { label: 'Origin shield header name', what: 'Optional header used to identify edge-origin traffic.', works: 'Can help origins trust requests from the CDN layer.', example: 'X-CDNLite-Origin-Shield' },
  origin_shield_secret: { label: 'Origin shield secret', what: 'Secret value sent with the shield header.', works: 'Required by the backend when origin shield is configured. Keep this secret; the dashboard redacts it from logs.', example: 'change-me-long-random-value', required: true },
};
const columns = [{ key: 'id', label: 'ID' }, { key: 'name', label: 'Name' }, { key: 'domain', label: 'Domain' }, { key: 'origin', label: 'Origin' }, { key: 'status', label: 'Status' }, { key: 'proxy_enabled', label: 'Proxy' }, { key: 'actions', label: 'Actions' }];
const siteRows = computed(() => sites.value.map((site) => ({ ...site, origin: `${site.origin_scheme ?? 'http'}://${site.origin_host}:${site.origin_port}`, actions: '' })));
async function load() { try { sites.value = await sitesApi.list(); } catch (error) { formError.value = messageFor(error, 'Unable to load sites.'); } }
async function saveSite() {
  clearErrors();
  const parsed = siteSchema.safeParse(form);
  if (!parsed.success) { applyValidationErrors(parsed.error); return; }
  if (!editingId.value && !String(form.origin_shield_secret).trim()) {
    fieldErrors.origin_shield_secret = 'Origin shield secret is required.';
    formError.value = 'Fix the highlighted fields before saving the site.';
    return;
  }
  saving.value = true;
  try {
    const payload: Record<string, unknown> = { ...form, origin_port: Number(form.origin_port), proxy_enabled: Boolean(form.proxy_enabled), geo_origins: JSON.parse(geoOrigins.value || '{}') };
    if (editingId.value && !String(payload.origin_shield_secret).trim()) delete payload.origin_shield_secret;
    if (editingId.value) await sitesApi.update(editingId.value, payload as UpdateSiteInput);
    else await sitesApi.create(payload as CreateSiteInput);
    resetForm();
    showForm.value = false;
    await load();
  } catch (error) {
    console.error('[sites-form]', error);
    formError.value = messageFor(error, editingId.value ? 'Unable to update site.' : 'Unable to create site.');
  }
  finally { saving.value = false; }
}
async function toggleProxy(row: Record<string, unknown>) { const id = String(row.id); if (row.proxy_enabled) await sitesApi.disableProxy(id); else await sitesApi.enableProxy(id); await load(); }
async function toggleStatus(row: Record<string, unknown>) { await sitesApi.update(String(row.id), { status: row.status === 'disabled' ? 'active' : 'disabled' }); await load(); }
async function deleteSite(id: string) { await sitesApi.remove(id); await load(); }
function editSite(row: Record<string, unknown>) {
  editingId.value = String(row.id);
  showForm.value = true;
  Object.assign(form, { name: String(row.name ?? ''), domain: String(row.domain ?? ''), origin_scheme: String(row.origin_scheme ?? 'http'), origin_host: String(row.origin_host ?? ''), origin_port: Number(row.origin_port ?? 80), proxy_enabled: Boolean(row.proxy_enabled), status: String(row.status ?? 'active'), origin_shield_header_name: String(row.origin_shield_header_name ?? ''), origin_shield_secret: String(row.origin_shield_secret ?? '') });
  geoOrigins.value = JSON.stringify(row.geo_origins ?? {}, null, 2);
  clearErrors();
}
function startCreate() { resetForm(); showForm.value = true; }
function resetForm() { editingId.value = ''; showForm.value = false; Object.assign(form, { name: '', domain: '', origin_scheme: 'http', origin_host: '', origin_port: 80, proxy_enabled: true, status: 'active', origin_shield_header_name: '', origin_shield_secret: '' }); geoOrigins.value = '{}'; clearErrors(); }
function clearErrors() { formError.value = ''; Object.keys(fieldErrors).forEach((key) => { delete fieldErrors[key]; }); }
function applyValidationErrors(error: z.ZodError) { error.issues.forEach((issue) => { fieldErrors[String(issue.path[0])] = issue.message; }); formError.value = 'Fix the highlighted fields before saving the site.'; }
function messageFor(error: unknown, fallback: string) { return error instanceof CdnLiteApiError || error instanceof Error ? error.message : fallback; }
onMounted(load);
</script>
