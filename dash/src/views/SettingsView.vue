<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
      <h1 class="text-3xl font-black text-slate-950 dark:text-white">Settings</h1>
      <p class="text-slate-600 dark:text-slate-400">Database-backed operational configuration. Environment variables remain fallback defaults.</p>
      </div>
      <ReportExportButton title="Settings" :data="{ group: active, values: draft }" />
    </div>

    <div class="card overflow-hidden">
      <div class="flex flex-wrap border-b border-slate-200 dark:border-slate-700">
        <button v-for="tab in tabs" :key="tab.key" class="px-4 py-3 text-sm font-bold" :class="active === tab.key ? 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-200' : 'text-slate-500'" @click="active = tab.key">{{ tab.label }}</button>
      </div>
      <div class="space-y-6 p-5">
        <div v-if="loading" class="text-sm text-slate-500">Loading settings...</div>
        <template v-else-if="group">
          <SettingsSection :fields="group.fields" :values="draft" @change="setValue" />
          <div class="flex flex-wrap items-center gap-3">
            <button class="button-primary" :disabled="!dirty || saving" @click="save">{{ saving ? 'Saving...' : 'Save changes' }}</button>
            <button v-if="active === 'platform.powerdns'" class="button-secondary" :disabled="testing" @click="testPowerDns">{{ testing ? 'Testing...' : 'Test PowerDNS connection' }}</button>
            <span v-if="message" class="text-sm" :class="messageOk ? 'text-emerald-600' : 'text-rose-600'">{{ message }}</span>
          </div>
          <div>
            <h2 class="mb-3 text-lg font-black">Audit log</h2>
            <div v-if="group.audit.length === 0" class="text-sm text-slate-500">No changes recorded for this group.</div>
            <ol v-else class="space-y-3">
              <li v-for="entry in group.audit" :key="entry.id" class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                <div class="font-bold">{{ entry.key.split('.').at(-1)?.replaceAll('_', ' ') }}</div>
                <div class="text-slate-500">{{ entry.actor || 'unknown actor' }} · {{ new Date(entry.created_at * 1000).toLocaleString() }}</div>
              </li>
            </ol>
          </div>
        </template>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import ReportExportButton from '@/components/reports/ReportExportButton.vue';
import { settingsApi } from '@/lib/api/settings';
import type { SettingsGroup } from '@/types';

const tabs = [
  { key: 'platform.powerdns', label: 'PowerDNS' },
  { key: 'platform.nameservers', label: 'Nameservers' },
  { key: 'platform.edge_dns', label: 'Edge DNS' },
  { key: 'platform.cache', label: 'Cache Defaults' },
  { key: 'platform.analytics', label: 'Analytics' },
  { key: 'platform.security', label: 'Security' },
];
const active = ref(tabs[0].key);
const group = ref<SettingsGroup | null>(null);
const draft = ref<Record<string, unknown>>({});
const changed = ref<Record<string, unknown>>({});
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const message = ref('');
const messageOk = ref(true);
const dirty = computed(() => Object.keys(changed.value).length > 0);

async function load() {
  loading.value = true;
  message.value = '';
  try {
    group.value = await settingsApi.group(active.value);
    draft.value = { ...group.value.values };
    changed.value = {};
  } finally {
    loading.value = false;
  }
}
function setValue(key: string, value: unknown) {
  draft.value = { ...draft.value, [key]: value };
  changed.value = { ...changed.value, [key]: value };
}
async function save() {
  saving.value = true;
  try {
    const validation = await settingsApi.validate(active.value, changed.value);
    if (!validation.valid) throw new Error(Object.values(validation.errors).join(', '));
    group.value = await settingsApi.update(active.value, changed.value);
    draft.value = { ...group.value.values };
    changed.value = {};
    messageOk.value = true;
    message.value = 'Settings saved.';
  } catch (error) {
    messageOk.value = false;
    message.value = error instanceof Error ? error.message : 'Unable to save settings.';
  } finally {
    saving.value = false;
  }
}
async function testPowerDns() {
  testing.value = true;
  try {
    const result = await settingsApi.testPowerDns();
    messageOk.value = result.ok;
    message.value = result.ok ? 'PowerDNS connection succeeded.' : `PowerDNS connection failed (${result.error || result.status}).`;
  } finally {
    testing.value = false;
  }
}
watch(active, load);
onMounted(load);
</script>
