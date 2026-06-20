<template>
  <div v-if="!dismissed" class="panel-section space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 class="text-sm font-semibold uppercase tracking-normal text-slate-700 dark:text-slate-200">Guided onboarding</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Answer a few setup questions and CDNLite will recommend a starter profile.</p>
      </div>
      <StatusBadge :status="state?.status === 'completed' ? 'healthy' : state?.status === 'skipped' ? 'warning' : 'info'" :label="state?.status ?? 'not started'" />
    </div>

    <div v-if="error" class="state-error">{{ error }}</div>
    <div v-if="message" role="status" class="notice-info">{{ message }}</div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
      <label class="field-label">
        Site type
        <select v-model="answers.site_type" class="input-field">
          <option value="website">Website</option>
          <option value="saas">SaaS app</option>
          <option value="api">API</option>
          <option value="ecommerce">E-commerce</option>
        </select>
      </label>
      <label class="field-label">
        Framework
        <select v-model="answers.framework" class="input-field">
          <option value="other">Other</option>
          <option value="wordpress">WordPress</option>
          <option value="laravel">Laravel</option>
          <option value="node">Node.js</option>
        </select>
      </label>
      <div class="field-label">
        Countries served
        <div class="mt-1 space-y-2">
          <select v-model="countryPicker" class="input-field" autocomplete="off" @change="addSelectedCountry">
            <option value="">Select a country</option>
            <option v-for="country in countryOptions" :key="country.code" :value="country.code" :disabled="selectedCountries.includes(country.code)">
              {{ country.name }} ({{ country.code }})
            </option>
          </select>
          <div class="flex min-h-9 flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-2 dark:border-white/10 dark:bg-slate-950">
            <button v-for="code in selectedCountries" :key="code" type="button" class="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100" @click="removeCountry(code)">
              {{ countryName(code) }} <span class="font-mono text-[11px]">{{ code }}</span>
            </button>
            <span v-if="!selectedCountries.length" class="px-1 py-1 text-xs font-normal text-slate-500 dark:text-slate-400">No country selected. CDNLite will use global defaults.</span>
          </div>
        </div>
      </div>
      <div class="grid gap-2 text-sm text-slate-600 dark:text-slate-300">
        <label class="inline-flex items-center gap-2"><input v-model="answers.has_login" type="checkbox" class="h-4 w-4 rounded border-slate-300" /> Users log in</label>
        <label class="inline-flex items-center gap-2"><input v-model="answers.has_api" type="checkbox" class="h-4 w-4 rounded border-slate-300" /> Has an API</label>
        <label class="inline-flex items-center gap-2"><input v-model="answers.sells_products" type="checkbox" class="h-4 w-4 rounded border-slate-300" /> Sells products</label>
        <label class="inline-flex items-center gap-2"><input v-model="answers.under_attack" type="checkbox" class="h-4 w-4 rounded border-slate-300" /> Currently under attack</label>
      </div>
    </div>

    <div v-if="state" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.8fr)]">
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-xs font-semibold uppercase text-slate-500">Recommendation</p>
        <p class="mt-1 text-base font-semibold text-slate-950 dark:text-white">{{ state.recommendation.name }}</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ state.recommendation.reason }}</p>
        <p v-if="preview" class="mt-2 text-xs text-slate-500">{{ preview.profile_preview.intents.length }} protection outcomes and {{ previewRuleCount }} technical rules will be generated.</p>
      </div>
      <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
        <p class="text-xs font-semibold uppercase text-slate-500">Setup progress</p>
        <div class="mt-2 grid gap-2">
          <div v-for="step in state.progress" :key="step.key" class="flex items-center justify-between gap-2 text-sm">
            <span class="text-slate-600 dark:text-slate-300">{{ step.label }}</span>
            <StatusBadge :status="step.status === 'complete' ? 'healthy' : 'unknown'" :label="step.status" />
          </div>
        </div>
      </div>
    </div>

    <div v-if="preview" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.03]">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-xs font-semibold uppercase text-slate-500">Full preview details</p>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">These exact managed rules will be generated if you apply the recommendation.</p>
        </div>
        <StatusBadge status="info" :label="`${previewRuleCount} rules`" />
      </div>
      <div class="mt-3 space-y-3">
        <div v-for="intent in preview.profile_preview.intents" :key="intent.intent_key" class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
          <p class="font-semibold text-slate-950 dark:text-white">{{ intent.name }}</p>
          <p class="mt-1 text-xs text-slate-500">Mode: {{ intent.mode }} · Risk: {{ intent.risk }}</p>
          <div class="mt-3 grid gap-3">
            <details v-for="rule in intent.rules" :key="`${intent.intent_key}:${rule.rule_table}:${rule.template_key}`" class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-slate-950/40" open>
              <summary class="cursor-pointer text-sm font-semibold text-slate-800 dark:text-slate-100">
                {{ humanize(rule.rule_table) }} · {{ rule.template_key }}
              </summary>
              <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ ruleEffect(rule) }}</p>
              <pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-white">{{ JSON.stringify(rule.payload ?? {}, null, 2) }}</pre>
            </details>
          </div>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <button class="button-primary" :disabled="busy" @click="saveAnswers">
        <Sparkles class="h-4 w-4" /> Recommend profile
      </button>
      <button class="button-secondary" :disabled="busy || !state" @click="previewProfile">
        <Eye class="h-4 w-4" /> Preview changes
      </button>
      <button class="button-primary" :disabled="busy || !state" @click="applyProfile">
        <ShieldCheck class="h-4 w-4" /> Apply recommended profile
      </button>
      <button class="button-secondary" :disabled="busy" @click="skip">
        Skip
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Eye, ShieldCheck, Sparkles } from 'lucide-vue-next';
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
