<template>
  <section class="space-y-6">
    <PageHeader eyebrow="Infrastructure" title="Domains" description="Manage domain identity, delegation, and lifecycle across your CDN.">
      <template #actions>
        <button type="button" class="button-primary min-w-32" @click="startCreate"><Plus class="h-4 w-4" /> Add domain</button>
      </template>
    </PageHeader>
    <AddDomainWizard v-if="showForm && !editingId" @cancel="resetForm" @completed="onOnboardingCompleted" />
    <form v-if="showForm && editingId" class="card grid gap-4 p-4 sm:p-5 xl:grid-cols-2" @submit.prevent="saveDomain">
      <div v-if="formError" role="alert" class="xl:col-span-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-700">{{ formError }}</div>
      <TextInput v-model="form.name" :help="{ ...help.name, error: fieldErrors.name }" />
      <TextInput v-model="form.domain" :help="{ ...help.domain, error: fieldErrors.domain }" />
      <p class="text-sm text-slate-500">Lifecycle is managed automatically from authoritative nameserver verification.</p>
      <div class="xl:col-span-2 flex justify-end gap-2"><button type="button" class="button-secondary" @click="resetForm">Cancel edit</button><button class="button-primary" :disabled="saving">Save changes</button></div>
    </form>
    <DataTable title="Domain inventory" subtitle="Domain identity, activation state, and authoritative delegation." search-placeholder="Search by name, domain, or ID..." :rows="domainRows" :columns="columns">
      <template #identity="{ row }">
        <div class="min-w-0">
          <RouterLink :to="`/domains/${row.id}/overview`" class="block truncate font-semibold text-slate-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300" :title="String(row.name)">{{ row.name }}</RouterLink>
          <span class="mt-1 block max-w-72 truncate font-mono text-xs text-slate-500" :title="String(row.id)">{{ row.id }}</span>
        </div>
      </template>
      <template #domain="{ value }"><span class="block max-w-72 truncate font-medium text-slate-700 dark:text-slate-200" :title="String(value)">{{ value }}</span></template>
      <template #status="{ row }">
        <StatusBadge compact :status="String(row.status ?? 'active')" :label="lifecycleLabel(row.status)" :title="`Lifecycle: ${String(row.status ?? 'active').replaceAll('_', ' ')}`" />
      </template>
      <template #nameserver_status="{ value }">
        <StatusBadge compact :status="nameserverSeverity(value)" :label="nameserverLabel(value)" :title="`Nameserver status: ${String(value ?? 'unknown').replaceAll('_', ' ')}`" />
      </template>
      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
          <RouterLink class="button-secondary h-9 px-3 text-xs" :to="`/domains/${row.id}/overview`">Manage <ArrowUpRight class="h-3.5 w-3.5" /></RouterLink>
          <button class="button-secondary h-9 px-3 text-xs" @click="editDomain(row)"><Pencil class="h-3.5 w-3.5" /> Edit</button>
          <button class="button-danger h-9 px-2.5 text-xs" :aria-label="`Delete ${row.domain}`" type="button" @click="openDeleteDomain(row)"><Trash2 class="h-3.5 w-3.5" /><span class="sr-only">Delete</span></button>
        </div>
      </template>
    </DataTable>
    <div v-if="pendingDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="cancelDeleteDomain">
      <section class="w-full max-w-lg rounded-lg border border-rose-200 bg-white p-5 shadow-2xl dark:border-rose-400/30 dark:bg-slate-950" role="dialog" aria-modal="true" aria-labelledby="delete-domain-title">
        <div class="flex items-start gap-3">
          <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200"><AlertTriangle class="h-5 w-5" /></div>
          <div class="min-w-0">
            <h2 id="delete-domain-title" class="text-base font-semibold text-slate-950 dark:text-white">Delete domain</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">This removes the domain, DNS records, origins, rules, SSL jobs, certificates, and edge configuration.</p>
          </div>
        </div>
        <div class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-400/20 dark:bg-rose-500/10 dark:text-rose-100">
          Type <b class="font-mono">{{ pendingDelete.domain }}</b> to confirm deletion.
        </div>
        <label class="mt-4 block">
          <span class="field-label">Domain confirmation</span>
          <input v-model="deleteConfirmation" class="input font-mono" autocomplete="off" :placeholder="pendingDelete.domain" @keydown.enter.prevent="confirmDeleteDomain" />
        </label>
        <p v-if="deleteError" class="mt-3 text-sm font-medium text-rose-600 dark:text-rose-300">{{ deleteError }}</p>
        <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
          <button type="button" class="button-secondary" :disabled="deletingDomain" @click="cancelDeleteDomain">Cancel</button>
          <button type="button" class="button-danger" :disabled="!canConfirmDelete || deletingDomain" @click="confirmDeleteDomain">{{ deletingDomain ? 'Deleting...' : 'Delete domain' }}</button>
        </div>
      </section>
    </div>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { z } from 'zod';
import { AlertTriangle, ArrowUpRight, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import TextInput from '@/components/forms/TextInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AddDomainWizard from '@/components/domains/AddDomainWizard.vue';
import { domainsApi } from '@/lib/api/domains';
import { CdnLiteApiError } from '@/lib/api/client';
import type { Domain, UpdateDomainInput } from '@/types';
const domains=ref<Domain[]>([]);const saving=ref(false);const editingId=ref('');const showForm=ref(false);const formError=ref('');const fieldErrors=reactive<Record<string,string>>({});
const pendingDelete=ref<Domain|null>(null);const deleteConfirmation=ref('');const deletingDomain=ref(false);const deleteError=ref('');
const form=reactive({name:'',domain:''});
const schema=z.object({name:z.string().min(1,'Domain name is required.'),domain:z.string().min(1,'Domain is required.')});
const help={name:{label:'Name',what:'Human-readable domain name.',works:'Used only for administration.',example:'Main website',required:true},domain:{label:'Domain',what:'Hostname served by the CDN.',works:'Matches the incoming Host header.',example:'example.com',required:true}};
const columns=[
  {key:'identity',label:'Identity',class:'w-[31%]'},
  {key:'domain',label:'Domain',class:'w-[25%]'},
  {key:'status',label:'Lifecycle',class:'w-[11%]'},
  {key:'nameserver_status',label:'NS status',class:'w-[11%]'},
  {key:'actions',label:'Actions',sortable:false,align:'right' as const,class:'w-[22%]'},
];
const domainRows=computed(()=>domains.value.map(domain=>({...domain,actions:''})));
const canConfirmDelete=computed(()=>pendingDelete.value!==null&&deleteConfirmation.value.trim()===pendingDelete.value.domain);
async function load(){try{domains.value=await domainsApi.list();}catch(error){formError.value=messageFor(error,'Unable to load domains.');}}
async function saveDomain(){clearErrors();const parsed=schema.safeParse(form);if(!parsed.success){parsed.error.issues.forEach(issue=>fieldErrors[String(issue.path[0])]=issue.message);formError.value='Fix the highlighted fields.';return;}saving.value=true;try{await domainsApi.update(editingId.value,{...form} as UpdateDomainInput);resetForm();await load();}catch(error){formError.value=messageFor(error,'Unable to update domain.');}finally{saving.value=false;}}
function openDeleteDomain(row:Record<string,unknown>){const domain=domains.value.find(item=>item.id===String(row.id));if(!domain)return;pendingDelete.value=domain;deleteConfirmation.value='';deleteError.value='';}
function cancelDeleteDomain(){if(deletingDomain.value)return;pendingDelete.value=null;deleteConfirmation.value='';deleteError.value='';}
async function confirmDeleteDomain(){if(!pendingDelete.value||!canConfirmDelete.value)return;deletingDomain.value=true;deleteError.value='';try{await domainsApi.remove(pendingDelete.value.id);pendingDelete.value=null;deleteConfirmation.value='';await load();}catch(error){deleteError.value=messageFor(error,'Unable to delete domain.');}finally{deletingDomain.value=false;}}
function editDomain(row:Record<string,unknown>){editingId.value=String(row.id);showForm.value=true;Object.assign(form,{name:String(row.name??''),domain:String(row.domain??'')});clearErrors();}
function startCreate(){resetForm();showForm.value=true;}
async function onOnboardingCompleted(){resetForm();await load();}
function resetForm(){editingId.value='';showForm.value=false;Object.assign(form,{name:'',domain:''});clearErrors();}
function clearErrors(){formError.value='';Object.keys(fieldErrors).forEach(key=>delete fieldErrors[key]);}
function messageFor(error:unknown,fallback:string){return error instanceof CdnLiteApiError||error instanceof Error?error.message:fallback;}
function lifecycleLabel(value:unknown){const status=String(value??'active');return status==='pending_nameserver'?'Pending':status.charAt(0).toUpperCase()+status.slice(1).replaceAll('_',' ');}
function nameserverLabel(value:unknown){const status=String(value??'unknown');if(status==='verified')return'Verified';if(status==='not_configured')return'Not set';return status.charAt(0).toUpperCase()+status.slice(1).replaceAll('_',' ');}
function nameserverSeverity(value:unknown){const status=String(value??'unknown');if(status==='verified')return'ok';if(status==='partial'||status==='not_configured')return'warning';return'unknown';}
onMounted(load);
</script>
