<template>
  <section class="space-y-5">
    <div class="section-heading mb-0">
      <div>
        <h2>Waiting Room</h2>
        <p>Control origin admission during overload while safe cached traffic keeps serving.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="button-secondary" :disabled="loading || saving" @click="load">
          <RefreshCw class="h-4 w-4" /> Refresh
        </button>
        <button class="button-primary" :disabled="saving || !policy" @click="save">
          <Save class="h-4 w-4" /> Save policy
        </button>
      </div>
    </div>

    <div v-if="message" role="status" class="notice-info">{{ message }}</div>
    <div v-if="error" class="state-error">{{ error }}</div>

    <div v-if="policy" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <div class="metric-panel">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase text-slate-500">Protection</p>
                <p class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">{{ draft.enabled ? 'Enabled' : 'Disabled' }}</p>
              </div>
              <StatusBadge :status="draft.enabled ? 'healthy' : 'unknown'" :label="draft.enabled ? 'On' : 'Off'" />
            </div>
          </div>
          <div class="metric-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Mode</p>
            <p class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">{{ modeLabel }}</p>
          </div>
          <div class="metric-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Runtime state</p>
            <div class="mt-1 flex items-center gap-2">
              <StatusBadge :status="stateStatus" :label="stateLabel" />
            </div>
          </div>
          <div class="metric-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Admission</p>
            <p class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">{{ draft.admission_rate_per_minute ?? 0 }}/min</p>
          </div>
        </div>

        <div class="panel-section space-y-5">
          <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px] lg:items-start">
            <div>
              <h3 class="text-base font-semibold text-slate-950 dark:text-white">Operating mode</h3>
              <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choose how the edge decides when to queue visitors.</p>
            </div>
            <label class="setting-row min-h-0 p-3">
              <span>
                <b>Waiting room enabled</b>
                <small>Policies remain saved when disabled.</small>
              </span>
              <input v-model="draft.enabled" class="toggle" type="checkbox" />
            </label>
          </div>

          <div class="grid gap-3 md:grid-cols-3">
            <button
              v-for="option in modeOptions"
              :key="option.value"
              type="button"
              class="rounded-lg border p-4 text-left transition focus:outline-none focus:ring-4 focus:ring-cyan-500/20"
              :class="draft.mode === option.value ? 'border-cyan-300 bg-cyan-50 dark:border-cyan-300/30 dark:bg-cyan-400/10' : 'border-slate-200 bg-slate-50 hover:border-slate-300 dark:border-white/10 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]'"
              @click="draft.mode = option.value"
            >
              <component :is="option.icon" class="h-5 w-5 text-cyan-700 dark:text-cyan-300" />
              <span class="mt-3 block text-sm font-semibold text-slate-950 dark:text-white">{{ option.label }}</span>
              <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">{{ option.description }}</span>
            </button>
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <label>
              <span class="field-label">Runtime state</span>
              <select v-model="draft.state" class="input">
                <option value="disabled">Disabled</option>
                <option value="monitoring">Monitoring</option>
                <option value="healthy">Healthy</option>
                <option value="entering_overload">Entering overload</option>
                <option value="overloaded">Overloaded</option>
                <option value="recovering">Recovering</option>
                <option value="manual_emergency">Manual emergency</option>
              </select>
            </label>
            <label>
              <span class="field-label">Reason</span>
              <input v-model="draft.reason" class="input" maxlength="160" placeholder="automatic_rps_threshold" />
            </label>
          </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
          <div class="panel-section space-y-4">
            <div class="flex items-start gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-cyan-50 text-cyan-700 ring-1 ring-cyan-200 dark:bg-cyan-400/10 dark:text-cyan-300 dark:ring-cyan-300/20">
                <Gauge class="h-5 w-5" />
              </div>
              <div>
                <h3 class="text-base font-semibold text-slate-950 dark:text-white">Admission budget</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Limits for queue population and origin-bound traffic.</p>
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <NumberField v-for="field in admissionFields" :key="field.key" v-model="draft[field.key]" :field="field" />
            </div>
          </div>

          <div class="panel-section space-y-4">
            <div class="flex items-start gap-3">
              <div class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200 dark:bg-indigo-400/10 dark:text-indigo-300 dark:ring-indigo-300/20">
                <Activity class="h-5 w-5" />
              </div>
              <div>
                <h3 class="text-base font-semibold text-slate-950 dark:text-white">Detection and recovery</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Thresholds and hysteresis for automatic overload state.</p>
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <NumberField v-for="field in detectionFields" :key="field.key" v-model="draft[field.key]" :field="field" />
            </div>
          </div>
        </div>

        <div class="panel-section space-y-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <h3 class="text-base font-semibold text-slate-950 dark:text-white">Visitor page</h3>
              <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Text shown by the edge-generated queue page.</p>
            </div>
            <StatusBadge status="info" label="No-store edge page" />
          </div>
          <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-3">
              <label>
                <span class="field-label">Title</span>
                <input v-model="draft.waiting_room_title" class="input" maxlength="120" />
              </label>
              <label>
                <span class="field-label">Message</span>
                <textarea v-model="draft.waiting_room_message" class="input min-h-28 py-3" maxlength="500" />
              </label>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
              <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950">
                <p class="text-xs font-semibold uppercase text-slate-500">Preview</p>
                <h4 class="mt-3 text-lg font-semibold text-slate-950 dark:text-white">{{ draft.waiting_room_title || 'Traffic is high' }}</h4>
                <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ draft.waiting_room_message || 'You are in a short waiting room while this site protects its origin.' }}</p>
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300">
                  Estimated wait: {{ draft.status_poll_seconds ?? 5 }}-{{ estimatedMaxWait }} seconds
                </div>
                <p class="mt-3 font-mono text-xs text-slate-400">Request ID: req_preview</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <aside class="space-y-4">
        <div class="panel-section space-y-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold text-slate-950 dark:text-white">Live counters</h3>
              <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Latest counters reported by the policy store.</p>
            </div>
            <BarChart3 class="h-5 w-5 text-slate-400" />
          </div>
          <div class="grid gap-2">
            <div v-for="item in counterItems" :key="item.label" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 dark:border-white/10">
              <span class="text-sm text-slate-500 dark:text-slate-400">{{ item.label }}</span>
              <span class="font-mono text-sm font-semibold text-slate-950 dark:text-white">{{ item.value }}</span>
            </div>
          </div>
        </div>

        <div class="panel-section space-y-4 border-amber-200 bg-amber-50 dark:border-amber-300/20 dark:bg-amber-400/10">
          <div class="flex items-start gap-3">
            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-800 dark:bg-amber-300/15 dark:text-amber-200">
              <Siren class="h-5 w-5" />
            </div>
            <div>
              <h3 class="text-base font-semibold text-amber-950 dark:text-amber-100">Emergency admission</h3>
              <p class="mt-1 text-sm text-amber-800 dark:text-amber-100/80">Manual emergency mode queues browser traffic immediately.</p>
            </div>
          </div>
          <label>
            <span class="field-label text-amber-900 dark:text-amber-100">Emergency reason</span>
            <input v-model="emergencyReason" class="input" maxlength="160" />
          </label>
          <div class="grid gap-2">
            <button class="button-danger justify-center border-amber-300 bg-white text-amber-800 hover:border-red-300 hover:text-red-700 dark:border-amber-300/20 dark:bg-slate-950/40 dark:text-amber-100" :disabled="saving" @click="activateEmergency">
              <Siren class="h-4 w-4" /> Activate for 1 hour
            </button>
            <button class="button-secondary justify-center" :disabled="saving" @click="deactivateEmergency">
              <Power class="h-4 w-4" /> Deactivate emergency
            </button>
          </div>
        </div>

        <div class="panel-section space-y-3">
          <h3 class="text-base font-semibold text-slate-950 dark:text-white">Edge behavior</h3>
          <div class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
            <div class="flex items-start gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><span>Safe cache hits continue during overload.</span></div>
            <div class="flex items-start gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><span>Unsafe methods receive bounded JSON errors.</span></div>
            <div class="flex items-start gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><span>Queue endpoints are served locally at the edge.</span></div>
          </div>
        </div>
      </aside>
    </div>

    <div v-else class="panel-section">
      <div class="h-5 w-48 animate-pulse rounded bg-slate-200 dark:bg-white/10"></div>
      <div class="mt-4 grid gap-3 md:grid-cols-4">
        <div v-for="item in 4" :key="item" class="h-24 animate-pulse rounded-lg bg-slate-100 dark:bg-white/[0.04]"></div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, onMounted, ref } from 'vue';
import {
  Activity, BarChart3, CheckCircle2, Gauge, MonitorDot, Power, RefreshCw,
  Save, Settings2, Siren, SlidersHorizontal,
} from 'lucide-vue-next';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { waitingRoomApi } from '@/lib/api/waitingRoom';
import type { Severity, WaitingRoomPolicy } from '@/types';

type NumericKey = keyof Pick<WaitingRoomPolicy,
  'rps_threshold' | 'active_origin_threshold' | 'origin_latency_ms_threshold' | 'origin_error_rate_threshold' |
  'admission_rate_per_minute' | 'queue_limit' | 'per_client_ticket_limit' | 'ticket_ttl_seconds' |
  'admission_ttl_seconds' | 'status_poll_seconds' | 'jitter_seconds' | 'unhealthy_windows' |
  'healthy_windows' | 'minimum_state_seconds' | 'recovery_ramp_percent'
>;
type NumericField = { key: NumericKey; label: string; suffix?: string; min: number; max: number };

const props = defineProps<{ domainId: string }>();
const policy = ref<WaitingRoomPolicy | null>(null);
const draft = ref<Partial<WaitingRoomPolicy>>({});
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const error = ref('');
const emergencyReason = ref('dashboard_emergency');

const NumberField = defineComponent({
  props: {
    modelValue: { type: Number, required: false },
    field: { type: Object as () => NumericField, required: true },
  },
  emits: ['update:modelValue'],
  setup(componentProps, { emit }) {
    return () => h('label', {}, [
      h('span', { class: 'field-label' }, componentProps.field.label),
      h('div', { class: 'relative' }, [
        h('input', {
          class: ['input', componentProps.field.suffix ? 'pr-14' : ''],
          type: 'number',
          min: componentProps.field.min,
          max: componentProps.field.max,
          value: componentProps.modelValue ?? '',
          onInput: (event: Event) => emit('update:modelValue', Number((event.target as HTMLInputElement).value)),
        }),
        componentProps.field.suffix
          ? h('span', { class: 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400' }, componentProps.field.suffix)
          : null,
      ]),
    ]);
  },
});

const modeOptions = [
  { value: 'monitoring', label: 'Monitoring', icon: MonitorDot, description: 'Collect signals without queueing visitors.' },
  { value: 'automatic', label: 'Automatic', icon: Settings2, description: 'Enter overload from edge thresholds.' },
  { value: 'manual', label: 'Manual', icon: SlidersHorizontal, description: 'Queue only from explicit operator state.' },
];

const admissionFields: NumericField[] = [
  { key: 'admission_rate_per_minute', label: 'Admission rate', suffix: '/min', min: 1, max: 1000000 },
  { key: 'queue_limit', label: 'Queue population', min: 1, max: 1000000 },
  { key: 'per_client_ticket_limit', label: 'Tickets per client', min: 1, max: 1000 },
  { key: 'ticket_ttl_seconds', label: 'Ticket TTL', suffix: 'sec', min: 30, max: 3600 },
  { key: 'admission_ttl_seconds', label: 'Admission TTL', suffix: 'sec', min: 60, max: 86400 },
  { key: 'status_poll_seconds', label: 'Status polling', suffix: 'sec', min: 2, max: 300 },
  { key: 'jitter_seconds', label: 'Polling jitter', suffix: 'sec', min: 0, max: 300 },
];

const detectionFields: NumericField[] = [
  { key: 'rps_threshold', label: 'Incoming RPS', min: 1, max: 1000000 },
  { key: 'active_origin_threshold', label: 'Active origin requests', min: 1, max: 1000000 },
  { key: 'origin_latency_ms_threshold', label: 'Origin latency', suffix: 'ms', min: 1, max: 600000 },
  { key: 'origin_error_rate_threshold', label: 'Origin error ratio', suffix: '%', min: 1, max: 100 },
  { key: 'unhealthy_windows', label: 'Unhealthy windows', min: 1, max: 100 },
  { key: 'healthy_windows', label: 'Healthy windows', min: 1, max: 100 },
  { key: 'minimum_state_seconds', label: 'Minimum state age', suffix: 'sec', min: 1, max: 86400 },
  { key: 'recovery_ramp_percent', label: 'Recovery ramp', suffix: '%', min: 1, max: 100 },
];

const stateLabel = computed(() => humanize(String(draft.value.state || 'disabled')));
const modeLabel = computed(() => humanize(String(draft.value.mode || 'monitoring')));
const estimatedMaxWait = computed(() => Number(draft.value.status_poll_seconds || 5) + Number(draft.value.jitter_seconds || 0));
const stateStatus = computed<Severity>(() => {
  const state = String(draft.value.state || '');
  if (!draft.value.enabled || state === 'disabled') return 'unknown';
  if (state === 'healthy' || state === 'monitoring') return 'healthy';
  if (state === 'recovering' || state === 'entering_overload') return 'warning';
  if (state === 'overloaded' || state === 'manual_emergency') return 'critical';
  return 'info';
});
const counterItems = computed(() => {
  const counters = policy.value?.counters || {};
  return [
    { label: 'Waiting', value: formatCounter(counters.waiting) },
    { label: 'Admitted', value: formatCounter(counters.admitted) },
    { label: 'Rejected', value: formatCounter(counters.rejected) },
    { label: 'Origin bound', value: formatCounter(counters.origin_bound) },
    { label: 'Cache served', value: formatCounter(counters.cache_served) },
    { label: 'Active origin', value: formatCounter(counters.active_origin) },
  ];
});

async function load() {
  loading.value = true;
  error.value = '';
  try {
    policy.value = await waitingRoomApi.get(props.domainId);
    draft.value = { ...policy.value };
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not load waiting room policy.';
  } finally {
    loading.value = false;
  }
}
async function save() {
  saving.value = true;
  error.value = '';
  try {
    policy.value = await waitingRoomApi.update(props.domainId, draft.value);
    draft.value = { ...policy.value };
    message.value = 'Waiting room policy saved.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not save waiting room policy.';
  } finally {
    saving.value = false;
  }
}
async function activateEmergency() {
  saving.value = true;
  error.value = '';
  try {
    policy.value = await waitingRoomApi.activateEmergency(props.domainId, { ttl_seconds: 3600, reason: emergencyReason.value || 'dashboard_emergency' });
    draft.value = { ...policy.value };
    message.value = 'Emergency waiting room activated.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not activate emergency mode.';
  } finally {
    saving.value = false;
  }
}
async function deactivateEmergency() {
  saving.value = true;
  error.value = '';
  try {
    policy.value = await waitingRoomApi.deactivateEmergency(props.domainId);
    draft.value = { ...policy.value };
    message.value = 'Emergency waiting room deactivated.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not deactivate emergency mode.';
  } finally {
    saving.value = false;
  }
}
function humanize(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}
function formatCounter(value: unknown) {
  return Number(value || 0).toLocaleString();
}

onMounted(load);
</script>
