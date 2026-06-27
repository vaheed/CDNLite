<template>
  <section class="space-y-4">
    <div class="toolbar-row">
      <div>
        <h2 class="section-title">Waiting room</h2>
        <p class="section-subtitle">Control origin admission during overload while safe cached traffic keeps serving.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="button-secondary" :disabled="loading" @click="load">Refresh</button>
        <button class="button-primary" :disabled="saving || !policy" @click="save">Save policy</button>
      </div>
    </div>

    <div v-if="policy" class="grid gap-4 xl:grid-cols-[1.1fr_.9fr]">
      <form class="panel space-y-4" @submit.prevent="save">
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="field-card"><span>Enabled</span><input v-model="draft.enabled" type="checkbox" /></label>
          <label class="field-card"><span>Mode</span><select v-model="draft.mode" class="input"><option value="monitoring">Monitoring</option><option value="automatic">Automatic</option><option value="manual">Manual</option></select></label>
          <label class="field-card"><span>State</span><select v-model="draft.state" class="input"><option value="disabled">Disabled</option><option value="monitoring">Monitoring</option><option value="healthy">Healthy</option><option value="entering_overload">Entering overload</option><option value="overloaded">Overloaded</option><option value="recovering">Recovering</option><option value="manual_emergency">Manual emergency</option></select></label>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <label v-for="field in numericFields" :key="field.key" class="field-card"><span>{{ field.label }}</span><input v-model.number="(draft as any)[field.key]" class="input" type="number" :min="field.min" :max="field.max" /></label>
        </div>
        <label class="field-card"><span>Waiting page title</span><input v-model="draft.waiting_room_title" class="input" maxlength="120" /></label>
        <label class="field-card"><span>Waiting page message</span><textarea v-model="draft.waiting_room_message" class="input min-h-28 py-3" maxlength="500" /></label>
        <p v-if="message" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200">{{ message }}</p>
      </form>

      <aside class="panel space-y-4">
        <div class="grid gap-2 sm:grid-cols-2">
          <div class="metric"><span>Current state</span><b>{{ policy.state }}</b></div>
          <div class="metric"><span>Reason</span><b>{{ policy.reason || 'None' }}</b></div>
          <div class="metric"><span>Admission rate</span><b>{{ policy.admission_rate_per_minute }}/min</b></div>
          <div class="metric"><span>Queue limit</span><b>{{ policy.queue_limit }}</b></div>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-300/20 dark:bg-amber-400/10">
          <h3 class="font-semibold text-amber-900 dark:text-amber-100">Emergency control</h3>
          <div class="mt-3 flex flex-wrap gap-2">
            <button class="button-danger" :disabled="saving" @click="activateEmergency">Activate 1 hour</button>
            <button class="button-secondary" :disabled="saving" @click="deactivateEmergency">Deactivate</button>
          </div>
        </div>
      </aside>
    </div>
    <div v-else class="panel">Loading waiting room policy...</div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { waitingRoomApi } from '@/lib/api/waitingRoom';
import type { WaitingRoomPolicy } from '@/types';

const props = defineProps<{ domainId: string }>();
const policy = ref<WaitingRoomPolicy | null>(null);
const draft = ref<Partial<WaitingRoomPolicy>>({});
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const numericFields = [
  ['rps_threshold', 'RPS threshold', 1, 1000000], ['active_origin_threshold', 'Active origin threshold', 1, 1000000],
  ['origin_latency_ms_threshold', 'Latency threshold ms', 1, 600000], ['origin_error_rate_threshold', 'Error threshold %', 1, 100],
  ['admission_rate_per_minute', 'Admission per minute', 1, 1000000], ['queue_limit', 'Queue limit', 1, 1000000],
  ['per_client_ticket_limit', 'Tickets per client', 1, 1000], ['ticket_ttl_seconds', 'Ticket TTL seconds', 30, 3600],
  ['admission_ttl_seconds', 'Admission TTL seconds', 60, 86400], ['status_poll_seconds', 'Poll seconds', 2, 300],
  ['jitter_seconds', 'Jitter seconds', 0, 300], ['unhealthy_windows', 'Unhealthy windows', 1, 100],
  ['healthy_windows', 'Healthy windows', 1, 100], ['minimum_state_seconds', 'Min state seconds', 1, 86400],
  ['recovery_ramp_percent', 'Recovery ramp %', 1, 100],
].map(([key, label, min, max]) => ({ key, label, min, max }));

async function load() {
  loading.value = true;
  try { policy.value = await waitingRoomApi.get(props.domainId); draft.value = { ...policy.value }; }
  finally { loading.value = false; }
}
async function save() {
  saving.value = true;
  try { policy.value = await waitingRoomApi.update(props.domainId, draft.value); draft.value = { ...policy.value }; message.value = 'Waiting room policy saved.'; }
  finally { saving.value = false; }
}
async function activateEmergency() {
  saving.value = true;
  try { policy.value = await waitingRoomApi.activateEmergency(props.domainId, { ttl_seconds: 3600, reason: 'dashboard_emergency' }); draft.value = { ...policy.value }; message.value = 'Emergency waiting room activated.'; }
  finally { saving.value = false; }
}
async function deactivateEmergency() {
  saving.value = true;
  try { policy.value = await waitingRoomApi.deactivateEmergency(props.domainId); draft.value = { ...policy.value }; message.value = 'Emergency waiting room deactivated.'; }
  finally { saving.value = false; }
}
onMounted(load);
</script>
