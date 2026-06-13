<template>
  <div class="card space-y-5 p-5">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Step {{ step }} of 4</p>
      <h2 class="text-xl font-black text-slate-950 dark:text-white">{{ titles[step - 1] }}</h2>
    </div>
    <div v-if="error" role="alert" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>
    <form v-if="step === 1" class="space-y-4" @submit.prevent="create">
      <TextInput v-model="zoneName" :help="{ label: 'Domain', what: 'The apex domain you want CDNLite to serve.', works: 'CDNLite uses it to verify authoritative nameserver delegation.', example: 'example.com', required: true }" />
      <TextInput v-model="displayName" :help="{ label: 'Display name', what: 'Optional admin label.', works: 'Shown only in the dashboard.', example: 'Main website' }" />
      <div class="flex justify-end gap-2"><button type="button" class="button-secondary" @click="$emit('cancel')">Cancel</button><button class="button-primary" :disabled="busy">Continue</button></div>
    </form>
    <div v-else-if="step === 2" class="space-y-4">
      <p class="text-sm text-slate-600 dark:text-slate-300">Set these authoritative nameservers at your registrar.</p>
      <div v-for="ns in domain?.nameservers ?? []" :key="ns.hostname" class="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
        <code>{{ ns.hostname }}</code><CopyButton :text="ns.hostname" label="Copy nameserver" />
      </div>
      <div class="flex justify-end"><button class="button-primary" @click="step = 3">I updated them</button></div>
    </div>
    <div v-else-if="step === 3" class="space-y-4">
      <p class="text-sm text-slate-600 dark:text-slate-300">Current status: <StatusBadge :status="domain?.nameserver_status ?? 'unknown'" /></p>
      <div class="flex justify-end gap-2"><button class="button-secondary" :disabled="busy" @click="verify">Check nameservers</button><button v-if="domain?.nameserver_status === 'verified'" class="button-primary" @click="step = 4">Continue</button></div>
    </div>
    <div v-else class="space-y-4">
      <p class="text-sm text-slate-600 dark:text-slate-300">Delegation is verified. The domain and its desired-active DNS records are now enabled automatically.</p>
      <div class="flex justify-end"><button class="button-primary" @click="complete">Done</button></div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { ref } from 'vue';
import TextInput from '@/components/forms/TextInput.vue';
import CopyButton from '@/components/ui/CopyButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { domainsApi } from '@/lib/api/domains';
import type { Domain } from '@/types';

const emit = defineEmits<{ cancel: []; completed: [domain: Domain] }>();
const step = ref(1); const zoneName = ref(''); const displayName = ref(''); const domain = ref<Domain | null>(null); const busy = ref(false); const error = ref('');
const titles = ['Enter your domain', 'Update nameservers', 'Verify delegation', 'Activate domain'];
async function create() { await run(async () => { domain.value = await domainsApi.create({ zone_name: zoneName.value, display_name: displayName.value || undefined }); step.value = 2; }); }
async function verify() { await run(async () => { domain.value = await domainsApi.verifyNameservers(domain.value!.id); if (domain.value.nameserver_status === 'verified') step.value = 4; }); }
function complete() { emit('completed', domain.value!); }
async function run(action: () => Promise<void>) { busy.value = true; error.value = ''; try { await action(); } catch (cause) { error.value = cause instanceof Error ? cause.message : 'Unable to continue onboarding.'; } finally { busy.value = false; } }
</script>
