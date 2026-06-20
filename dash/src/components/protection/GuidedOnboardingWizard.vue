<template>
  <div class="panel-section space-y-4">
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
      <label class="field-label">
        Countries served
        <input v-model="countriesText" class="input-field" placeholder="US, DE, JP" />
      </label>
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
        <p v-if="preview" class="mt-2 text-xs text-slate-500">{{ preview.profile_preview.intents.length }} protection outcomes will be generated.</p>
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
      <button v-if="state?.status === 'skipped'" class="button-secondary" :disabled="busy" @click="resume">
        Resume
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
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
const countriesText = ref('');
const answers = reactive<OnboardingAnswers>({
  site_type: 'website',
  framework: 'other',
  has_login: false,
  has_api: false,
  sells_products: false,
  under_attack: false,
  enable_now: false,
});

async function load() {
  error.value = '';
  try {
    state.value = await protectionApi.getOnboarding(props.domainId);
    Object.assign(answers, state.value.answers);
    countriesText.value = state.value.answers.countries?.join(', ') ?? '';
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
      countries: countriesText.value.split(',').map((item) => item.trim().toUpperCase()).filter(Boolean),
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
    message.value = 'Onboarding skipped. You can resume from here later.';
  });
}

async function resume() {
  await run(async () => {
    state.value = await protectionApi.resumeOnboarding(props.domainId);
    message.value = 'Onboarding resumed.';
  });
}

watch(() => props.domainId, load);
onMounted(load);
</script>
