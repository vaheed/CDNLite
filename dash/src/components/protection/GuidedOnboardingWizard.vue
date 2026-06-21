<template>
  <div v-if="!dismissed" class="panel-section space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-3 dark:border-white/10">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Guided onboarding</p>
        <h3 class="mt-0.5 text-base font-semibold tracking-tight text-slate-950 dark:text-white">Tune the starter protection profile</h3>
        <p class="mt-0.5 max-w-2xl text-xs leading-5 text-slate-500 dark:text-slate-400">Answer a few operational questions and CDNLite will recommend a focused security baseline for this domain.</p>
      </div>
      <StatusBadge :status="state?.status === 'completed' ? 'healthy' : state?.status === 'skipped' ? 'warning' : 'info'" :label="state?.status ?? 'not started'" />
    </div>

    <div v-if="error" class="state-error">{{ error }}</div>
    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.4fr_1fr]">
      <SelectField id="onboarding-site-type" v-model="answers.site_type" label="Site type" helper="Used to choose sensible rule groups." compact>
          <option value="website">Website</option>
          <option value="saas">SaaS app</option>
          <option value="api">API</option>
          <option value="ecommerce">E-commerce</option>
      </SelectField>
      <SelectField id="onboarding-framework" v-model="answers.framework" label="Framework" helper="Helps avoid noisy generic rules." compact>
          <option value="other">Other</option>
          <option value="wordpress">WordPress</option>
          <option value="laravel">Laravel</option>
          <option value="node">Node.js</option>
      </SelectField>
      <FormField label="Countries served" helper="Leave empty to use global defaults for all regions.">
        <div class="space-y-1.5">
          <div class="relative">
            <select id="onboarding-countries" v-model="countryPicker" class="input h-9 appearance-none pr-9 text-sm" autocomplete="off" @change="addSelectedCountry">
            <option value="">Select a country</option>
            <option v-for="country in countryOptions" :key="country.code" :value="country.code" :disabled="selectedCountries.includes(country.code)">
              {{ country.name }} ({{ country.code }})
            </option>
            </select>
            <ChevronDown class="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
          </div>
          <div class="flex min-h-9 flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-slate-50/70 p-1.5 dark:border-white/10 dark:bg-slate-950/60">
            <button v-for="code in selectedCountries" :key="code" type="button" class="inline-flex min-h-6 items-center gap-1 rounded-full border border-sky-200 bg-white px-2 text-[11px] font-semibold text-sky-700 shadow-sm transition hover:border-sky-300 focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100" :aria-label="`Remove ${countryName(code)}`" @click="removeCountry(code)">
              {{ countryName(code) }} <span class="font-mono text-[11px]">{{ code }}</span><X class="h-3 w-3" />
            </button>
            <span v-if="!selectedCountries.length" class="px-1 py-1 text-xs font-normal text-slate-500 dark:text-slate-400">Global default: serve every country.</span>
          </div>
        </div>
      </FormField>
      <div class="grid content-start gap-1.5">
        <label class="compact-check-row"><input v-model="answers.has_login" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-white/20 dark:bg-slate-950" /><span><strong>Users log in</strong><small>Protect sessions and account paths.</small></span></label>
        <label class="compact-check-row"><input v-model="answers.has_api" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-white/20 dark:bg-slate-950" /><span><strong>Has an API</strong><small>Prefer API-aware rate limits.</small></span></label>
        <label class="compact-check-row"><input v-model="answers.sells_products" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-white/20 dark:bg-slate-950" /><span><strong>Sells products</strong><small>Include checkout protections.</small></span></label>
        <label class="compact-check-row"><input v-model="answers.under_attack" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 dark:border-white/20 dark:bg-slate-950" /><span><strong>Currently under attack</strong><small>Use a tighter posture.</small></span></label>
      </div>
    </div>

    <div v-if="state" class="grid gap-3 lg:grid-cols-[minmax(0,1.25fr)_minmax(260px,0.75fr)]">
      <div class="rounded-lg border border-cyan-200 bg-cyan-50/70 p-3 dark:border-cyan-300/20 dark:bg-cyan-400/10">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-cyan-800 dark:text-cyan-200">Recommendation</p>
            <p class="mt-0.5 text-base font-semibold text-slate-950 dark:text-white">{{ state.recommendation.name }}</p>
            <p class="mt-0.5 text-xs leading-5 text-slate-600 dark:text-slate-300">{{ state.recommendation.reason }}</p>
          </div>
          <StatusBadge :status="riskStatus(preview?.profile_preview.risk)" :label="riskLabel(preview?.profile_preview.risk)" />
        </div>
        <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-stretch">
          <div class="rounded-lg border border-cyan-200 bg-white/80 p-2 dark:border-cyan-300/20 dark:bg-slate-950/30">
            <span class="text-xs text-slate-500 dark:text-slate-400">Outcomes</span>
            <strong class="mt-0.5 block text-lg text-slate-950 dark:text-white">{{ preview?.profile_preview.intents.length ?? state.progress.length }}</strong>
          </div>
          <div class="rounded-lg border border-cyan-200 bg-white/80 p-2 dark:border-cyan-300/20 dark:bg-slate-950/30">
            <span class="text-xs text-slate-500 dark:text-slate-400">Generated rules</span>
            <strong class="mt-0.5 block text-lg text-slate-950 dark:text-white">{{ previewRuleCount }}</strong>
          </div>
          <button type="button" class="button-primary min-h-9 justify-center px-3 py-2 text-sm" :disabled="busy || !state" @click="applyProfile">
            <ShieldCheck class="h-4 w-4" /> Apply profile
          </button>
        </div>
      </div>
      <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Setup progress</p>
        <div class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50/60 dark:divide-white/10 dark:border-white/10 dark:bg-white/[0.03]">
          <div v-for="(step, index) in state.progress" :key="step.key" class="flex items-center gap-2 px-2.5 py-2 text-sm">
            <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full border text-[10px] font-bold" :class="step.status === 'complete' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-300/20 dark:bg-emerald-400/10 dark:text-emerald-200' : index === nextStepIndex ? 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-300/20 dark:bg-cyan-400/10 dark:text-cyan-200' : 'border-slate-200 bg-white text-slate-400 dark:border-white/10 dark:bg-slate-950'">
              <Check v-if="step.status === 'complete'" class="h-3 w-3" />
              <span v-else>{{ index + 1 }}</span>
            </span>
            <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
              <span class="truncate font-medium text-slate-800 dark:text-slate-100">{{ step.label }}</span>
              <span class="shrink-0 text-xs capitalize text-slate-500 dark:text-slate-400">{{ step.status === 'complete' ? 'Complete' : index === nextStepIndex ? 'Current' : 'Pending' }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="preview" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.03]">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Full preview details</p>
          <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Exact managed rules generated by this recommendation.</p>
        </div>
        <StatusBadge status="info" :label="`${previewRuleCount} rules`" />
      </div>
      <div class="mt-2 space-y-2">
        <Accordion v-for="intent in preview.profile_preview.intents" :key="intent.intent_key" :title="intent.name" :subtitle="`${intent.rules.length} rules · ${modeLabel(intent.mode)} · ${riskLabel(intent.risk)}`" :default-open="preview.profile_preview.intents.length === 1">
          <div class="mb-2 flex flex-wrap gap-2">
            <StatusBadge status="info" :label="modeLabel(intent.mode)" />
            <StatusBadge :status="riskStatus(intent.risk)" :label="riskLabel(intent.risk)" />
          </div>
          <div class="mt-2 grid gap-2">
            <div v-for="rule in intent.rules" :key="`${intent.intent_key}:${rule.rule_table}:${rule.template_key}`" class="rounded-lg border border-slate-200 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-slate-950/40">
              <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ humanize(rule.rule_table) }}</p>
                  <p class="mt-1 break-words font-mono text-xs text-slate-500 dark:text-slate-400">{{ rule.template_key }}</p>
                  <p class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-300">{{ ruleEffect(rule) }}</p>
                </div>
                <StatusBadge status="unknown" :label="rule.managed_by || 'Managed'" />
              </div>
              <dl class="mt-2 grid gap-2 text-xs sm:grid-cols-3">
                <div class="rounded-lg bg-white p-2 dark:bg-white/[0.04]"><dt class="font-semibold uppercase text-slate-500">Type</dt><dd class="mt-1 font-mono text-slate-800 dark:text-slate-100">{{ rule.rule_table }}</dd></div>
                <div class="rounded-lg bg-white p-2 dark:bg-white/[0.04]"><dt class="font-semibold uppercase text-slate-500">Mode</dt><dd class="mt-1 text-slate-800 dark:text-slate-100">{{ modeLabel(intent.mode) }}</dd></div>
                <div class="rounded-lg bg-white p-2 dark:bg-white/[0.04]"><dt class="font-semibold uppercase text-slate-500">Risk</dt><dd class="mt-1 text-slate-800 dark:text-slate-100">{{ riskLabel(intent.risk) }}</dd></div>
              </dl>
              <details class="mt-2 rounded-lg border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950">
                <summary class="cursor-pointer px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:text-slate-200">Advanced details</summary>
                <pre class="max-h-64 overflow-auto border-t border-slate-200 p-2 text-xs text-slate-800 dark:border-white/10 dark:text-slate-100">{{ JSON.stringify(rule.payload ?? {}, null, 2) }}</pre>
              </details>
            </div>
          </div>
        </Accordion>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 pt-1">
      <button class="button-primary px-3 py-2 text-sm" :disabled="busy" @click="saveAnswers">
        <Sparkles class="h-4 w-4" /> Recommend profile
      </button>
      <button class="button-secondary px-3 py-2 text-sm" :disabled="busy || !state" @click="previewProfile">
        <Eye class="h-4 w-4" /> Preview changes
      </button>
      <button class="button-primary px-3 py-2 text-sm" :disabled="busy || !state" @click="applyProfile">
        <ShieldCheck class="h-4 w-4" /> Apply recommended profile
      </button>
      <button class="button-secondary px-3 py-2 text-sm" :disabled="busy" @click="skip">
        Skip
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Check, ChevronDown, Eye, ShieldCheck, Sparkles, X } from 'lucide-vue-next';
import Accordion from '@/components/ui/Accordion.vue';
import FormField from '@/components/ui/FormField.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { protectionApi } from '@/lib/api/protection';
import type { OnboardingAnswers, OnboardingPreview, OnboardingState } from '@/types';

const props = defineProps<{ domainId: string }>();
const emit = defineEmits<{ applied: [] }>();
const state = ref<OnboardingState | null>(null);
const preview = ref<OnboardingPreview | null>(null);
const busy = ref(false);
const error = ref('');
const message = ref('');
const dismissed = ref(false);
const countryPicker = ref('');
const selectedCountries = ref<string[]>([]);
const answers = reactive<OnboardingAnswers>({
  site_type: 'website',
  framework: 'other',
  has_login: false,
  has_api: false,
  sells_products: false,
  under_attack: false,
  enable_now: false,
});
const countryOptions = [
  { code: 'US', name: 'United States' },
  { code: 'CA', name: 'Canada' },
  { code: 'GB', name: 'United Kingdom' },
  { code: 'DE', name: 'Germany' },
  { code: 'FR', name: 'France' },
  { code: 'NL', name: 'Netherlands' },
  { code: 'IR', name: 'Iran' },
  { code: 'TR', name: 'Turkey' },
  { code: 'AE', name: 'United Arab Emirates' },
  { code: 'IN', name: 'India' },
  { code: 'SG', name: 'Singapore' },
  { code: 'JP', name: 'Japan' },
  { code: 'AU', name: 'Australia' },
  { code: 'BR', name: 'Brazil' },
];
const previewRuleCount = computed(() => preview.value?.profile_preview.intents.reduce((total, intent) => total + intent.rules.length, 0) ?? 0);
const nextStepIndex = computed(() => state.value?.progress.findIndex((step) => step.status !== 'complete') ?? -1);

async function load() {
  error.value = '';
  dismissed.value = false;
  try {
    state.value = await protectionApi.getOnboarding(props.domainId);
    if (state.value.status === 'skipped') {
      dismissed.value = true;
      return;
    }
    Object.assign(answers, state.value.answers);
    selectedCountries.value = normalizeCountries(state.value.answers.countries ?? []);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not load onboarding.';
  }
}

async function run(action: () => Promise<void>) {
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Onboarding action failed.';
  } finally {
    busy.value = false;
  }
}

async function saveAnswers() {
  await run(async () => {
    state.value = await protectionApi.saveOnboardingAnswers(props.domainId, {
      ...answers,
      countries: selectedCountries.value,
    });
    preview.value = null;
    message.value = `Recommended ${state.value.recommendation.name}.`;
  });
}

async function previewProfile() {
  await run(async () => {
    preview.value = await protectionApi.previewOnboarding(props.domainId);
    state.value = preview.value.onboarding;
    message.value = `${preview.value.profile_preview.name} preview loaded.`;
  });
}

async function applyProfile() {
  await run(async () => {
    const result = await protectionApi.applyOnboarding(props.domainId);
    state.value = result.onboarding;
    message.value = `Applied ${state.value.recommendation.name}.`;
    emit('applied');
  });
}

async function skip() {
  await run(async () => {
    state.value = await protectionApi.skipOnboarding(props.domainId);
    dismissed.value = true;
  });
}

function addSelectedCountry() {
  const code = countryPicker.value;
  if (code && !selectedCountries.value.includes(code)) {
    selectedCountries.value = [...selectedCountries.value, code];
  }
  countryPicker.value = '';
}

function removeCountry(code: string) {
  selectedCountries.value = selectedCountries.value.filter((item) => item !== code);
}

function countryName(code: string) {
  return countryOptions.find((country) => country.code === code)?.name ?? code;
}

function normalizeCountries(values: string[]) {
  return values.map((item) => item.trim().toUpperCase()).filter((item) => /^[A-Z]{2}$/.test(item));
}

function humanize(value: string) {
  return value.replaceAll('_', ' ');
}

function riskStatus(risk?: string): 'ok' | 'warning' | 'critical' | 'info' {
  if (risk === 'risky') return 'critical';
  if (risk === 'moderate') return 'warning';
  if (risk === 'safe') return 'ok';
  return 'info';
}

function riskLabel(risk?: string) {
  return risk ? `${humanize(risk)} risk` : 'Risk pending';
}

function modeLabel(mode?: string) {
  return humanize(mode || 'recommended');
}

function ruleEffect(rule: { payload?: Record<string, unknown>; rule_table: string }) {
  const payload = rule.payload ?? {};
  const action = typeof payload.action === 'string' ? payload.action : null;
  const pattern = typeof payload.pattern === 'string' ? payload.pattern : typeof payload.path_prefix === 'string' ? payload.path_prefix : null;
  if (action && pattern) return `${humanize(action)} on ${pattern}`;
  if (pattern) return `Applies to ${pattern}`;
  if (typeof payload.ttl_seconds === 'number') return `Caches for ${payload.ttl_seconds} seconds`;
  if (typeof payload.requests_per_minute === 'number') return `Limits to ${payload.requests_per_minute} requests per minute`;
  return 'Creates an advanced managed rule';
}

watch(() => props.domainId, load);
onMounted(load);
</script>

<style scoped>
.compact-check-row {
  @apply flex min-h-9 items-start gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs text-slate-600 transition hover:border-cyan-200 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300;
}

.compact-check-row strong {
  @apply block text-xs font-semibold leading-4 text-slate-800 dark:text-slate-100;
}

.compact-check-row small {
  @apply mt-0.5 block text-[11px] leading-4 text-slate-500 dark:text-slate-400;
}
</style>
