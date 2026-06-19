<template>
  <section class="space-y-4">
    <div class="panel-section space-y-3">
      <div class="section-heading mb-0">
        <div>
          <h2>Smart Rate Limiting</h2>
          <p>Start with a dry run, then apply the smallest rule that protects the path.</p>
        </div>
      </div>
      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div v-for="item in suggestions" :key="item.path" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/[0.03]">
          <div class="font-medium text-slate-800 dark:text-slate-100">{{ item.label }}</div>
          <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ item.path }}</div>
        </div>
      </div>
      <p class="text-sm text-slate-500 dark:text-slate-400">Use `dry-run` from the API to preview impact before saving a rule. Header-based keys can track `Authorization` or another token header without collapsing all missing headers into one bucket.</p>
    </div>
    <DomainRulesTab
      :domain-id="domainId"
      title="Rate Limits"
      summary="Limit request volume by IP or IP and path."
      :fields="fields"
      :columns="columns"
      :help-items="helpItems"
      :list="() => rateLimitApi.list(domainId)"
      :create="(v) => rateLimitApi.create(domainId, v as never)"
      :update="(id, v) => rateLimitApi.update(domainId, id, v)"
      :detach-managed="(id) => rateLimitApi.detachManaged(domainId, id)"
      :remove="(id) => rateLimitApi.delete(domainId, id)"
    />
  </section>
</template>
<script setup lang="ts">
import DomainRulesTab from './DomainRulesTab.vue';
import { rateLimitApi } from '@/lib/api/rateLimit';

defineProps<{ domainId: string }>();

const suggestions = [
  { label: 'Login protection', path: '/login, /admin, /wp-login.php' },
  { label: 'API protection', path: '/api/* with Authorization header keys' },
  { label: 'Form spam', path: '/contact, /signup' },
  { label: 'Expensive pages', path: 'selected slow paths or /checkout' },
];
const helpItems = [
  { title: 'Path examples', body: 'Use / for site-wide limits, /login for authentication, or /api/ for API traffic.' },
  { title: 'Thresholds', body: 'Start higher than normal traffic, review analytics, then tighten for sensitive endpoints.' },
  { title: 'Key type', body: 'IP groups all requests from one visitor. Header keys can limit API tokens or Authorization headers.' },
];
const fields = [
  { key: 'enabled', label: 'Enabled', type: 'checkbox' as const, default: true, help: 'Disable instead of deleting when testing traffic impact.' },
  { key: 'path_prefix', label: 'Path prefix', default: '/', placeholder: '/login', help: 'Apply the limit only to requests whose path starts with this value.' },
  { key: 'requests_per_minute', label: 'Requests/minute', type: 'number' as const, default: 60, placeholder: '60', help: 'The maximum requests allowed per key during one minute.' },
  { key: 'key_type', label: 'Key type', options: ['ip', 'ip_path', 'header', 'header_path'], default: 'ip', help: 'Choose how visitors, paths, or request headers are counted for this limit.' },
  { key: 'key_header_name', label: 'Header key', default: '', placeholder: 'Authorization', help: 'Required for header-based keys. Missing headers fall back to IP at the edge.' },
  { key: 'priority', label: 'Priority', type: 'number' as const, default: 100, placeholder: '100', help: 'Lower priority values are evaluated first.' },
  { key: 'action', label: 'Action', options: ['block', 'challenge'], default: 'block', help: 'Challenge adds friction before the limit turns into a hard block.' },
];
const columns = [
  { key: 'enabled', label: 'Enabled' },
  { key: 'path_prefix', label: 'Path' },
  { key: 'requests_per_minute', label: 'Requests/min' },
  { key: 'key_type', label: 'Key' },
  { key: 'key_header_name', label: 'Header' },
  { key: 'managed_by', label: 'Managed' },
  { key: 'actions', label: 'Actions' },
];
</script>
