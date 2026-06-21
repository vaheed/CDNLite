<template>
  <button class="button-secondary" type="button" @click="open = true">
    <FileText class="h-4 w-4" /> Preview report
  </button>

  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 bg-slate-950/60 p-0 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" :aria-labelledby="titleId" @click.self="open = false">
      <div class="flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-slate-950 sm:mx-auto sm:h-auto sm:max-h-[92vh] sm:max-w-5xl sm:rounded-xl sm:border sm:border-slate-200 dark:sm:border-white/10">
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 p-4 dark:border-white/10 sm:p-5">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Report preview</p>
            <h2 :id="titleId" class="mt-1 truncate text-lg font-semibold text-slate-950 dark:text-white">{{ title }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Generated {{ generatedAt }}</p>
          </div>
          <button class="icon-button" type="button" aria-label="Close report preview" @click="open = false">
            <X class="h-4 w-4" />
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-auto p-4 sm:p-5">
          <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="metric in summaryMetrics" :key="metric.label" class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/[0.025]">
              <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ metric.label }}</span>
              <strong class="mt-2 block text-2xl font-black text-slate-950 dark:text-white">{{ metric.value }}</strong>
              <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ metric.helper }}</span>
            </div>
          </div>

          <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.025]">
              <h3 class="text-sm font-semibold text-slate-950 dark:text-white">Sections</h3>
              <div class="mt-3 space-y-2">
                <div v-for="section in sections" :key="section.key" class="rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-white/10 dark:bg-slate-950/50">
                  <div class="flex items-center justify-between gap-3">
                    <span class="font-mono text-xs font-semibold text-slate-700 dark:text-slate-200">{{ section.key }}</span>
                    <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 dark:bg-white/[0.05] dark:ring-white/10">{{ section.kind }}</span>
                  </div>
                  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ section.summary }}</p>
                </div>
              </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-slate-950 p-4 text-white dark:border-white/10">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold">Markdown preview</h3>
                <button class="status-action border-white/10 bg-white/10 text-white hover:bg-white/15 hover:text-white" type="button" @click="copyMarkdown">
                  <Copy class="h-3.5 w-3.5" /> {{ copied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <pre class="mt-3 max-h-[42vh] overflow-auto whitespace-pre-wrap text-xs leading-5 text-slate-100">{{ markdown }}</pre>
            </section>
          </div>

          <details class="mt-4 rounded-xl border border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/[0.025]">
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-800 focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:text-slate-100">Advanced JSON</summary>
            <pre class="max-h-96 overflow-auto border-t border-slate-200 p-4 text-xs text-slate-700 dark:border-white/10 dark:text-slate-200">{{ jsonPreview }}</pre>
          </details>
        </div>

        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 p-4 sm:flex-row sm:justify-end dark:border-white/10">
          <button class="button-secondary w-full sm:w-auto" type="button" @click="open = false">Close</button>
          <button class="button-secondary w-full sm:w-auto" type="button" @click="downloadJson">
            <Download class="h-4 w-4" /> Download JSON
          </button>
          <button class="button-primary w-full sm:w-auto" type="button" @click="copyMarkdown">
            <Copy class="h-4 w-4" /> {{ copied ? 'Report copied' : 'Copy Markdown' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Copy, Download, FileText, X } from 'lucide-vue-next';
import { createMarkdownReport } from '@/lib/reports/markdown';

const props = defineProps<{ title: string; data: Record<string, unknown> }>();
const open = ref(false);
const copied = ref(false);
const titleId = `report-preview-${Math.random().toString(36).slice(2)}`;
const generatedAt = computed(() => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date()));
const markdown = computed(() => createMarkdownReport(props.title, props.data));
const jsonPreview = computed(() => JSON.stringify(props.data, null, 2));
const sections = computed(() => Object.entries(props.data).map(([key, value]) => ({
  key,
  kind: Array.isArray(value) ? 'list' : value && typeof value === 'object' ? 'object' : typeof value,
  summary: summarizeValue(value),
})));
const summaryMetrics = computed(() => [
  { label: 'Sections', value: sections.value.length, helper: 'Top-level report areas' },
  { label: 'Records', value: countRecords(props.data), helper: 'Items across arrays and objects' },
  { label: 'Size', value: `${Math.ceil(jsonPreview.value.length / 1024)} KB`, helper: 'Approximate JSON payload' },
]);

function summarizeValue(value: unknown) {
  if (Array.isArray(value)) return `${value.length} item${value.length === 1 ? '' : 's'} included.`;
  if (value && typeof value === 'object') return `${Object.keys(value).length} field${Object.keys(value).length === 1 ? '' : 's'} included.`;
  return String(value ?? 'No value');
}

function countRecords(value: unknown): number {
  if (Array.isArray(value)) return value.length + value.reduce((total, item) => total + countRecords(item), 0);
  if (value && typeof value === 'object') return Object.values(value).reduce((total, item) => total + countRecords(item), Object.keys(value).length);
  return 0;
}

async function copyMarkdown() {
  await copyText(markdown.value);
  copied.value = true;
  window.setTimeout(() => copied.value = false, 2000);
}

function downloadJson() {
  const blob = new Blob([jsonPreview.value], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${props.title.toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'report'}.json`;
  link.click();
  URL.revokeObjectURL(url);
}

async function copyText(text: string) {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return;
    } catch {}
  }
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.left = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();
  document.execCommand('copy');
  document.body.removeChild(textarea);
}
</script>
