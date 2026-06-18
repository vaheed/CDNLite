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

    <div class="grid gap-4 lg:grid-cols-3">
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

        <div class="mt-auto flex flex-wrap gap-2 pt-5">
          <button class="button-secondary" :disabled="busyKey === intent.intent_key" :aria-label="`Preview ${intent.name}`" @click="preview(intent)">
            <Eye class="h-4 w-4" /> Preview
          </button>
          <button v-if="intent.status !== 'enabled'" class="button-primary" :disabled="busyKey === intent.intent_key" :aria-label="`Enable ${intent.name}`" @click="enable(intent)">
            <ShieldCheck class="h-4 w-4" /> Enable
          </button>
          <button v-else class="button-secondary" :disabled="busyKey === intent.intent_key" :aria-label="`Disable ${intent.name}`" @click="disable(intent)">
            <ShieldOff class="h-4 w-4" /> Disable
          </button>
          <button v-if="intent.intent" class="button-secondary" :disabled="busyKey === intent.intent_key" :aria-label="`Undo ${intent.name}`" @click="undo(intent)">
            <Undo2 class="h-4 w-4" /> Undo
          </button>
        </div>
      </article>
    </div>

    <div v-if="previewResult" class="panel-section">
      <div class="section-heading">
        <div>
          <h2>{{ previewResult.name }} preview</h2>
          <p>Preview only shows the technical rules that will be generated. It does not change edge behavior.</p>
        </div>
        <StatusBadge :status="riskStatus(previewResult.risk)" :label="riskLabel(previewResult.risk)" />
      </div>
      <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-white/10">
        <table class="min-w-full text-left text-sm">
          <thead class="table-head"><tr><th>Rule type</th><th>Template</th><th>Effect</th></tr></thead>
          <tbody>
            <tr v-for="rule in previewResult.rules" :key="`${rule.rule_table}:${rule.template_key}`" class="border-t border-slate-200 dark:border-white/10">
              <td class="table-cell font-medium">{{ humanize(rule.rule_table) }}</td>
              <td class="table-cell font-mono text-xs">{{ rule.template_key }}</td>
              <td class="table-cell">{{ ruleEffect(rule) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Eye, RefreshCw, ShieldCheck, ShieldOff, Undo2 } from 'lucide-vue-next';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { protectionApi } from '@/lib/api/protection';
import { queryKeys } from '@/lib/data/queryKeys';
import { useInvalidationListener } from '@/lib/data/invalidation';
import type { ProtectionGeneratedRule, ProtectionIntentPreview, ProtectionIntentSummary, Severity } from '@/types';

const props = defineProps<{ domainId: string }>();
const intents = ref<ProtectionIntentSummary[]>([]);
const previewResult = ref<ProtectionIntentPreview | null>(null);
const loading = ref(false);
const busyKey = ref('');
const message = ref('');
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    intents.value = await protectionApi.listIntents(props.domainId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not load protection intents.';
  } finally {
    loading.value = false;
  }
}

async function preview(intent: ProtectionIntentSummary) {
  await runIntentAction(intent, async () => {
    previewResult.value = await protectionApi.previewIntent(props.domainId, intent.intent_key);
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
