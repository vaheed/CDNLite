<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div>
        <h2>Security Center</h2>
        <p>Choose protection outcomes here, then inspect or tune the generated rules in the advanced tabs.</p>
      </div>
      <button class="button-secondary" :disabled="loading" @click="load">
        <RefreshCw class="h-4 w-4" /> Refresh
      </button>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>
    <div v-if="error" class="state-error">{{ error }}</div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="profile in profiles" :key="profile.profile_key" class="panel-section flex min-h-60 flex-col">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-base font-semibold text-slate-950 dark:text-white">{{ profile.name }}</h3>
            <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{{ profile.summary }}</p>
          </div>
          <StatusBadge :status="profile.status === 'enabled' ? 'healthy' : 'unknown'" :label="statusLabel(profile.status)" />
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <StatusBadge :status="riskStatus(profile.risk)" :label="riskLabel(profile.risk)" />
          <StatusBadge status="info" :label="`${profile.intent_keys.length} intents`" />
        </div>

        <div class="mt-auto grid gap-2 pt-5 sm:flex sm:flex-wrap">
          <button class="button-secondary w-full sm:w-auto" :disabled="busyKey === profile.profile_key" :aria-label="`Preview ${profile.name}`" @click="previewProfile(profile)">
            <Eye class="h-4 w-4" /> Preview
          </button>
          <button v-if="profile.status !== 'enabled'" class="button-primary w-full sm:w-auto" :disabled="busyKey === profile.profile_key" :aria-label="`Apply ${profile.name}`" @click="applyProfile(profile)">
            <ShieldCheck class="h-4 w-4" /> Apply
          </button>
          <button v-else class="button-secondary w-full sm:w-auto" :disabled="busyKey === profile.profile_key" :aria-label="`Disable ${profile.name}`" @click="disableProfile(profile)">
            <ShieldOff class="h-4 w-4" /> Disable
          </button>
        </div>
      </article>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="intent in intents" :key="intent.intent_key" class="panel-section flex min-h-72 flex-col">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-base font-semibold text-slate-950 dark:text-white">{{ intent.name }}</h3>
            <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{{ intent.summary }}</p>
          </div>
          <StatusBadge :status="intent.status === 'enabled' ? 'healthy' : 'unknown'" :label="statusLabel(intent.status)" />
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <StatusBadge :status="riskStatus(intent.risk)" :label="riskLabel(intent.risk)" />
          <StatusBadge status="info" :label="modeLabel(intent.recommended_mode)" />
          <StatusBadge status="unknown" :label="ruleCountLabel(intent)" />
        </div>

        <div v-if="intent.generated_rules.length" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/[0.03]">
          <p class="text-xs font-semibold uppercase text-slate-500">Advanced rules</p>
          <ul class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
            <li v-for="rule in intent.generated_rules.slice(0, 3)" :key="`${rule.rule_table}:${rule.rule_id ?? rule.template_key}`" class="flex justify-between gap-3">
              <span class="font-medium">{{ humanize(rule.rule_table) }}</span>
              <span class="truncate text-right font-mono">{{ rule.template_key }}</span>
            </li>
          </ul>
        </div>

        <div class="mt-auto grid gap-2 pt-5 sm:flex sm:flex-wrap">
          <button class="button-secondary w-full sm:w-auto" :disabled="busyKey === intent.intent_key" :aria-label="`Preview ${intent.name}`" @click="preview(intent)">
            <Eye class="h-4 w-4" /> Preview
          </button>
          <button v-if="intent.status !== 'enabled'" class="button-primary w-full sm:w-auto" :disabled="busyKey === intent.intent_key" :aria-label="`Enable ${intent.name}`" @click="enable(intent)">
            <ShieldCheck class="h-4 w-4" /> Enable
          </button>
          <button v-else class="button-secondary w-full sm:w-auto" :disabled="busyKey === intent.intent_key" :aria-label="`Disable ${intent.name}`" @click="disable(intent)">
            <ShieldOff class="h-4 w-4" /> Disable
          </button>
          <button v-if="intent.intent" class="button-secondary w-full sm:w-auto" :disabled="busyKey === intent.intent_key" :aria-label="`Undo ${intent.name}`" @click="undo(intent)">
            <Undo2 class="h-4 w-4" /> Undo
          </button>
        </div>
      </article>
    </div>

    <div v-if="profilePreviewResult || previewResult" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" :aria-labelledby="previewTitleId" @click.self="closePreview">
      <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-950">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-4 dark:border-white/10 sm:p-5">
          <div class="min-w-0">
            <h2 :id="previewTitleId" class="truncate text-lg font-semibold text-slate-950 dark:text-white">{{ previewTitle }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-slate-400">Preview only shows the technical rules that will be generated. It does not change edge behavior.</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <StatusBadge :status="riskStatus(previewRisk)" :label="riskLabel(previewRisk)" />
            <button class="button-secondary px-3" aria-label="Close preview" @click="closePreview">
              <X class="h-4 w-4" />
            </button>
          </div>
        </div>

        <div class="min-h-0 overflow-auto p-4 sm:p-5">
          <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-white/10">
            <table class="min-w-[760px] text-left text-sm">
              <thead class="table-head">
                <tr>
                  <th v-if="profilePreviewResult">Intent</th>
                  <th>Rule type</th>
                  <th>Template</th>
                  <th>Effect</th>
                </tr>
              </thead>
              <tbody>
                <template v-if="profilePreviewResult">
                  <template v-for="intent in profilePreviewResult.intents" :key="intent.intent_key">
                    <tr v-for="rule in intent.rules" :key="`${intent.intent_key}:${rule.rule_table}:${rule.template_key}`" class="border-t border-slate-200 dark:border-white/10">
                      <td class="table-cell font-medium">{{ intent.name }}</td>
                      <td class="table-cell">{{ humanize(rule.rule_table) }}</td>
                      <td class="table-cell font-mono text-xs">{{ rule.template_key }}</td>
                      <td class="table-cell">{{ ruleEffect(rule) }}</td>
                    </tr>
                  </template>
                </template>
                <template v-else-if="previewResult">
                  <tr v-for="rule in previewResult.rules" :key="`${rule.rule_table}:${rule.template_key}`" class="border-t border-slate-200 dark:border-white/10">
                    <td class="table-cell font-medium">{{ humanize(rule.rule_table) }}</td>
                    <td class="table-cell font-mono text-xs">{{ rule.template_key }}</td>
                    <td class="table-cell">{{ ruleEffect(rule) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Eye, RefreshCw, ShieldCheck, ShieldOff, Undo2, X } from 'lucide-vue-next';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { protectionApi } from '@/lib/api/protection';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { ProtectionGeneratedRule, ProtectionIntentPreview, ProtectionIntentSummary, ProtectionProfilePreview, ProtectionProfileSummary, Severity } from '@/types';

const props = defineProps<{ domainId: string }>();
const profiles = ref<ProtectionProfileSummary[]>([]);
const intents = ref<ProtectionIntentSummary[]>([]);
const profilePreviewResult = ref<ProtectionProfilePreview | null>(null);
const previewResult = ref<ProtectionIntentPreview | null>(null);
const loading = ref(false);
const busyKey = ref('');
const message = ref('');
const error = ref('');
const previewTitleId = 'security-center-preview-title';

const previewTitle = computed(() => `${profilePreviewResult.value?.name ?? previewResult.value?.name ?? 'Protection'} preview`);
const previewRisk = computed(() => profilePreviewResult.value?.risk ?? previewResult.value?.risk ?? 'safe');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const [profileItems, intentItems] = await Promise.all([
      protectionApi.listProfiles(props.domainId),
      protectionApi.listIntents(props.domainId),
    ]);
    profiles.value = profileItems;
    intents.value = intentItems;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not load protection options.';
  } finally {
    loading.value = false;
  }
}

async function previewProfile(profile: ProtectionProfileSummary) {
  await runProfileAction(profile, async () => {
    profilePreviewResult.value = await protectionApi.previewProfile(props.domainId, profile.profile_key);
    previewResult.value = null;
    message.value = `${profile.name} preview loaded.`;
  }, false);
}

async function applyProfile(profile: ProtectionProfileSummary) {
  await runProfileAction(profile, async () => {
    await protectionApi.applyProfile(props.domainId, profile.profile_key);
    message.value = `${profile.name} applied.`;
  });
}

async function disableProfile(profile: ProtectionProfileSummary) {
  if (!profile.profile?.id) return;
  await runProfileAction(profile, async () => {
    await protectionApi.disableProfile(props.domainId, profile.profile?.id ?? '');
    message.value = `${profile.name} disabled.`;
  });
}

async function preview(intent: ProtectionIntentSummary) {
  await runIntentAction(intent, async () => {
    previewResult.value = await protectionApi.previewIntent(props.domainId, intent.intent_key);
    profilePreviewResult.value = null;
    message.value = `${intent.name} preview loaded.`;
  }, false);
}

async function enable(intent: ProtectionIntentSummary) {
  await runIntentAction(intent, async () => {
    await protectionApi.enableIntent(props.domainId, intent.intent_key);
    message.value = `${intent.name} enabled.`;
  });
}

async function disable(intent: ProtectionIntentSummary) {
  if (!intent.intent?.id) return;
  await runIntentAction(intent, async () => {
    await protectionApi.disableIntent(props.domainId, intent.intent?.id ?? '');
    message.value = `${intent.name} disabled.`;
  });
}

async function undo(intent: ProtectionIntentSummary) {
  if (!intent.intent?.id) return;
  await runIntentAction(intent, async () => {
    await protectionApi.undoIntent(props.domainId, intent.intent?.id ?? '');
    message.value = `${intent.name} restored from the latest rollback point.`;
  });
}

async function runIntentAction(intent: ProtectionIntentSummary, action: () => Promise<void>, reload = true) {
  busyKey.value = intent.intent_key;
  error.value = '';
  try {
    await action();
    if (reload) await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Protection action failed.';
  } finally {
    busyKey.value = '';
  }
}

async function runProfileAction(profile: ProtectionProfileSummary, action: () => Promise<void>, reload = true) {
  busyKey.value = profile.profile_key;
  error.value = '';
  try {
    await action();
    if (reload) await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Protection profile action failed.';
  } finally {
    busyKey.value = '';
  }
}

function closePreview() {
  profilePreviewResult.value = null;
  previewResult.value = null;
}

function statusLabel(status: string) {
  if (status === 'enabled') return 'Enabled';
  if (status === 'disabled') return 'Disabled';
  return 'Available';
}

function riskLabel(risk: string) {
  if (risk === 'safe') return 'Safe';
  if (risk === 'moderate') return 'Needs review';
  return 'Confirm first';
}

function riskStatus(risk: string): Severity {
  if (risk === 'safe') return 'healthy';
  if (risk === 'moderate') return 'warning';
  return 'critical';
}

function modeLabel(mode: string) {
  return humanize(mode || 'recommended');
}

function ruleCountLabel(intent: ProtectionIntentSummary) {
  const count = intent.generated_rules.length || expectedRuleCount(intent.intent_key);
  return `${count} generated ${count === 1 ? 'rule' : 'rules'}`;
}

function expectedRuleCount(intentKey: string) {
  const counts: Record<string, number> = {
    common_exploits: 2,
    login_shield: 2,
    protect_api: 2,
    smart_rate_limiting: 1,
    bot_shield: 2,
    emergency_protection: 2,
    static_asset_performance: 1,
  };
  return counts[intentKey] ?? 0;
}

function humanize(value: string) {
  return value.replaceAll('_', ' ');
}

function ruleEffect(rule: ProtectionGeneratedRule) {
  const payload = rule.payload ?? {};
  const action = typeof payload.action === 'string' ? payload.action : null;
  const pattern = typeof payload.pattern === 'string' ? payload.pattern : typeof payload.path_prefix === 'string' ? payload.path_prefix : null;
  if (action && pattern) return `${humanize(action)} on ${pattern}`;
  if (pattern) return `Applies to ${pattern}`;
  if (typeof payload.ttl_seconds === 'number') return `Caches for ${payload.ttl_seconds} seconds`;
  return 'Creates an advanced managed rule';
}

watch(() => props.domainId, load);
useInvalidationListener(() => [queryKeys.domain(props.domainId)], load);
onMounted(load);
</script>
