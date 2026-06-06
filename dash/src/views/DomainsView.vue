<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-black text-slate-950 dark:text-white">Domains</h1><p class="text-slate-600 dark:text-slate-400">Manage domain identity and lifecycle. Origins and proxy settings live on DNS records.</p></div>
    <div class="flex justify-end"><button type="button" class="button-primary" @click="startCreate">Add domain</button></div>
    <AddDomainWizard v-if="showForm && !editingId" @cancel="resetForm" @completed="onOnboardingCompleted" />
    <form v-if="showForm && editingId" class="card grid gap-4 p-4 sm:p-5 xl:grid-cols-2" @submit.prevent="saveDomain">
      <div v-if="formError" role="alert" class="xl:col-span-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700">{{ formError }}</div>
      <TextInput v-model="form.name" :help="{ ...help.name, error: fieldErrors.name }" />
      <TextInput v-model="form.domain" :help="{ ...help.domain, error: fieldErrors.domain }" />
      <label class="space-y-2"><span class="text-sm font-semibold">Status</span><select v-model="form.status" class="input"><option value="active">Active</option><option value="disabled">Disabled</option></select></label>
      <TextInput v-model="form.origin_shield_header_name" :help="help.origin_shield_header_name" />
      <TextInput v-model="form.origin_shield_secret" :help="{ ...help.origin_shield_secret, error: fieldErrors.origin_shield_secret }" />
      <div class="xl:col-span-2 flex justify-end gap-2"><button type="button" class="button-secondary" @click="resetForm">Cancel edit</button><button class="button-primary" :disabled="saving">Save changes</button></div>
    </form>
    <DataTable title="Domains" subtitle="Origin configuration is managed per DNS record." :rows="domainRows" :columns="columns">
      <template #id="{ value }"><div class="flex items-center gap-2"><code class="text-xs">{{ value }}</code><CopyButton :text="String(value)" label="Copy ID" /></div></template>
      <template #status="{ row }"><div class="flex flex-wrap items-center gap-2"><StatusBadge :status="String(row.status ?? 'active')" /><StatusBadge :status="String(row.nameserver_status ?? 'unknown')" :label="`NS: ${row.nameserver_status ?? 'unknown'}`" /><button v-if="row.status !== 'pending_nameserver'" class="button-secondary px-2 py-1 text-xs" @click="toggleStatus(row)">{{ row.status === 'disabled' ? 'Activate' : 'Disable' }}</button></div></template>
      <template #actions="{ row }"><div class="flex flex-wrap gap-2"><RouterLink class="button-primary px-2 py-1 text-xs" :to="`/domains/${row.id}/overview`">Manage</RouterLink><RouterLink class="button-secondary px-2 py-1 text-xs" :to="`/domains/${row.id}/dns`">DNS and origins</RouterLink><button class="button-secondary px-2 py-1 text-xs" @click="editDomain(row)">Edit domain</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this domain?" @confirm="deleteDomain(String(row.id))">Delete</ConfirmDangerButton></div></template>
    </DataTable>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { z } from 'zod';
import TextInput from '@/components/forms/TextInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import CopyButton from '@/components/ui/CopyButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import AddDomainWizard from '@/components/domains/AddDomainWizard.vue';
import { domainsApi } from '@/lib/api/domains';
import { CdnLiteApiError } from '@/lib/api/client';
import type { Domain, UpdateDomainInput } from '@/types';
const domains=ref<Domain[]>([]);const saving=ref(false);const editingId=ref('');const showForm=ref(false);const formError=ref('');const fieldErrors=reactive<Record<string,string>>({});
const form=reactive({name:'',domain:'',status:'active',origin_shield_header_name:'',origin_shield_secret:''});
const schema=z.object({name:z.string().min(1,'Domain name is required.'),domain:z.string().min(1,'Domain is required.')});
const help={name:{label:'Name',what:'Human-readable domain name.',works:'Used only for administration.',example:'Main website',required:true},domain:{label:'Domain',what:'Hostname served by the CDN.',works:'Matches the incoming Host header.',example:'example.com',required:true},origin_shield_header_name:{label:'Origin shield header name',what:'Optional edge-to-origin authentication header.',works:'Sent only on origin requests.',example:'X-CDNLite-Origin-Shield'},origin_shield_secret:{label:'Origin shield secret',what:'Optional secret sent to origins.',works:'Stored as a hash and loaded from the edge secret.',example:'long-random-value'}};
const columns=[{key:'id',label:'ID'},{key:'name',label:'Name'},{key:'domain',label:'Domain'},{key:'status',label:'Status'},{key:'actions',label:'Actions'}];
const domainRows=computed(()=>domains.value.map(domain=>({...domain,actions:''})));
async function load(){try{domains.value=await domainsApi.list();}catch(error){formError.value=messageFor(error,'Unable to load domains.');}}
async function saveDomain(){clearErrors();const parsed=schema.safeParse(form);if(!parsed.success){parsed.error.issues.forEach(issue=>fieldErrors[String(issue.path[0])]=issue.message);formError.value='Fix the highlighted fields.';return;}saving.value=true;try{const payload:Record<string,unknown>={...form};if(!String(payload.origin_shield_secret).trim())delete payload.origin_shield_secret;await domainsApi.update(editingId.value,payload as UpdateDomainInput);resetForm();await load();}catch(error){formError.value=messageFor(error,'Unable to update domain.');}finally{saving.value=false;}}
async function toggleStatus(row:Record<string,unknown>){await domainsApi.update(String(row.id),{status:row.status==='disabled'?'active':'disabled'});await load();}
async function deleteDomain(id:string){await domainsApi.remove(id);await load();}
function editDomain(row:Record<string,unknown>){editingId.value=String(row.id);showForm.value=true;Object.assign(form,{name:String(row.name??''),domain:String(row.domain??''),status:String(row.status??'active'),origin_shield_header_name:String(row.origin_shield_header_name??''),origin_shield_secret:''});clearErrors();}
function startCreate(){resetForm();showForm.value=true;}
async function onOnboardingCompleted(){resetForm();await load();}
function resetForm(){editingId.value='';showForm.value=false;Object.assign(form,{name:'',domain:'',status:'active',origin_shield_header_name:'',origin_shield_secret:''});clearErrors();}
function clearErrors(){formError.value='';Object.keys(fieldErrors).forEach(key=>delete fieldErrors[key]);}
function messageFor(error:unknown,fallback:string){return error instanceof CdnLiteApiError||error instanceof Error?error.message:fallback;}
onMounted(load);
</script>
