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
          <ConfirmDangerButton class="h-9 px-2.5 text-xs" :aria-label="`Delete ${row.domain}`" confirm-text="Delete this domain?" @confirm="deleteDomain(String(row.id))"><Trash2 class="h-3.5 w-3.5" /><span class="sr-only">Delete</span></ConfirmDangerButton>
        </div>
      </template>
    </DataTable>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { z } from 'zod';
import { ArrowUpRight, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import TextInput from '@/components/forms/TextInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import AddDomainWizard from '@/components/domains/AddDomainWizard.vue';
import { domainsApi } from '@/lib/api/domains';
import { CdnLiteApiError } from '@/lib/api/client';
import type { Domain, UpdateDomainInput } from '@/types';
const domains=ref<Domain[]>([]);const saving=ref(false);const editingId=ref('');const showForm=ref(false);const formError=ref('');const fieldErrors=reactive<Record<string,string>>({});
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
async function load(){try{domains.value=await domainsApi.list();}catch(error){formError.value=messageFor(error,'Unable to load domains.');}}
async function saveDomain(){clearErrors();const parsed=schema.safeParse(form);if(!parsed.success){parsed.error.issues.forEach(issue=>fieldErrors[String(issue.path[0])]=issue.message);formError.value='Fix the highlighted fields.';return;}saving.value=true;try{await domainsApi.update(editingId.value,{...form} as UpdateDomainInput);resetForm();await load();}catch(error){formError.value=messageFor(error,'Unable to update domain.');}finally{saving.value=false;}}
async function deleteDomain(id:string){await domainsApi.remove(id);await load();}
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
