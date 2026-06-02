<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-black text-white">Sites</h1><p class="text-slate-400">Full lifecycle management for CDN sites, origins, and proxy state.</p></div>
    <form class="card grid gap-4 p-5 xl:grid-cols-2" @submit.prevent="createSite">
      <TextInput v-model="form.name" :help="help.name" />
      <TextInput v-model="form.domain" :help="help.domain" />
      <TextInput v-model="form.origin_scheme" :help="help.origin_scheme" />
      <TextInput v-model="form.origin_host" :help="help.origin_host" />
      <TextInput v-model="form.origin_port" type="number" :help="help.origin_port" />
      <JsonEditorField v-model="geoOrigins" :help="help.geo_origins" />
      <TextInput v-model="form.origin_shield_header_name" :help="help.origin_shield_header_name" />
      <TextInput v-model="form.origin_shield_secret" :help="help.origin_shield_secret" />
      <div class="xl:col-span-2 flex justify-end"><button class="button-primary" :disabled="saving">Create site</button></div>
    </form>
    <DataTable title="Sites" subtitle="Search, sort, paginate, and copy IDs." :rows="siteRows" :columns="columns">
      <template #id="{ value }"><div class="flex items-center gap-2"><code class="text-xs">{{ value }}</code><CopyButton :text="String(value)" label="Copy ID" /></div></template>
      <template #proxy_enabled="{ row }"><button class="button-secondary px-2 py-1 text-xs" @click="toggleProxy(row)">{{ row.proxy_enabled ? 'Disable' : 'Enable' }}</button></template>
      <template #delete="{ row }"><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this site?" @confirm="deleteSite(String(row.id))">Delete</ConfirmDangerButton></template>
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
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import { sitesApi } from '@/lib/api/sites';
import type { Site } from '@/types';
const sites = ref<Site[]>([]); const saving = ref(false); const geoOrigins = ref('{\n  "eu": "https://eu-origin.example.com",\n  "us": "https://us-origin.example.com"\n}');
const form = reactive({ name: '', domain: '', origin_scheme: 'http', origin_host: '', origin_port: 80, proxy_enabled: true, status: 'active', origin_shield_header_name: '', origin_shield_secret: '' });
const siteSchema = z.object({ name: z.string().min(1), domain: z.string().min(1), origin_scheme: z.string().min(1), origin_host: z.string().min(1), origin_port: z.coerce.number().int().positive() });
const help = {
  name: { label: 'Name', what: 'Human-readable site name shown in the admin.', works: 'Does not affect routing; used for management.', example: 'Main Website', required: true },
  domain: { label: 'Domain', what: 'Hostname served by the CDN.', works: 'Edge matches incoming Host header to this domain.', example: 'example.com', required: true },
  origin_scheme: { label: 'Origin scheme', what: 'Protocol used when the edge connects to origin.', works: 'Usually http for Docker/internal origin, https for public origin.', example: 'https', required: true },
  origin_host: { label: 'Origin host', what: 'Host/IP of the upstream origin server.', works: 'Edge proxies cache misses to this origin.', example: 'origin.example.com', required: true },
  origin_port: { label: 'Origin port', what: 'Port of the upstream origin server.', works: 'Combined with scheme and host to build upstream URL.', example: '443', required: true },
  geo_origins: { label: 'Geo origins', what: 'Optional region-specific origins as JSON.', works: 'Edge can route by geo/region when config supports it.', example: '{ "eu": "https://eu-origin.example.com" }' },
  origin_shield_header_name: { label: 'Origin shield header name', what: 'Optional header used to identify edge-origin traffic.', works: 'Can help origins trust requests from the CDN layer.', example: 'X-CDNLite-Origin-Shield' },
  origin_shield_secret: { label: 'Origin shield secret', what: 'Secret value sent with the shield header.', works: 'Keep this secret; the dashboard redacts it from logs.', example: 'change-me-long-random-value' },
};
const columns = [{ key: 'id', label: 'ID' }, { key: 'name', label: 'Name' }, { key: 'domain', label: 'Domain' }, { key: 'origin', label: 'Origin' }, { key: 'status', label: 'Status' }, { key: 'proxy_enabled', label: 'Proxy' }, { key: 'delete', label: '' }];
const siteRows = computed(() => sites.value.map((site) => ({ ...site, origin: `${site.origin_scheme ?? 'http'}://${site.origin_host}:${site.origin_port}`, delete: '' })));
async function load() { sites.value = await sitesApi.list().catch(() => []); }
async function createSite() {
  const parsed = siteSchema.safeParse(form); if (!parsed.success) { alert(parsed.error.issues[0]?.message ?? 'Invalid site'); return; }
  saving.value = true;
  try { await sitesApi.create({ ...form, origin_port: Number(form.origin_port), proxy_enabled: Boolean(form.proxy_enabled), geo_origins: JSON.parse(geoOrigins.value || '{}') }); await load(); }
  finally { saving.value = false; }
}
async function toggleProxy(row: Record<string, unknown>) { const id = String(row.id); if (row.proxy_enabled) await sitesApi.disableProxy(id); else await sitesApi.enableProxy(id); await load(); }
async function deleteSite(id: string) { await sitesApi.remove(id); await load(); }
onMounted(load);
</script>
