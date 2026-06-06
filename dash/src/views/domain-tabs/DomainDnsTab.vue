<template>
  <section class="space-y-5">
    <div class="flex justify-between"><div><h2 class="text-xl font-black">DNS records</h2><p class="text-sm text-slate-500">Routing is configured per record. Anycast ingress VIPs are global platform settings.</p></div><button class="button-primary" @click="startCreate">Add record</button></div>
    <form v-if="editing" class="card grid gap-4 p-5 md:grid-cols-3" @submit.prevent="save">
      <label>Type<select v-model="form.type" class="input"><option>A</option><option>AAAA</option><option>CNAME</option><option>TXT</option><option>MX</option></select></label>
      <label>Name<input v-model="form.name" class="input" /></label>
      <label>{{ form.proxied ? 'Origin target' : 'Content' }}<input v-model="form.content" class="input" /></label>
      <label>TTL<input v-model="form.ttl" type="number" class="input" /></label>
      <label class="flex items-center gap-2"><input v-model="form.proxied" type="checkbox" @change="normalizePolicy" /> Proxied CDN</label>
      <label>Routing policy<select v-model="form.routing_policy" class="input">
        <option value="standard">Standard DNS</option><option value="geo">Geo DNS</option>
        <option v-if="form.proxied" value="anycast">Anycast CDN</option><option v-if="form.proxied" value="geo_anycast">Geo + Anycast CDN</option>
      </select></label>
      <div v-if="form.proxied" class="md:col-span-3 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
        <strong>Published DNS target:</strong> {{ publishedPreview }}
        <p>Public DNS points to CDNLite edge. The origin target is used by the edge proxy.</p>
        <p v-if="form.name === '@'" class="mt-1 font-semibold">Apex proxying requires PowerDNS ALIAS flattening or delegated authoritative DNS.</p>
      </div>
      <div v-if="isGeo" class="md:col-span-3 space-y-3">
        <div class="flex justify-between"><strong>Geo routing</strong><button type="button" class="button-secondary" @click="addGeoRoute">Add country rule</button></div>
        <p class="text-xs text-amber-700">Geo policy storage is active; country-aware PowerDNS responses require a GeoDNS backend/plugin.</p>
        <div v-for="(route,index) in geoRoutes" :key="index" class="grid gap-2 md:grid-cols-3">
          <label>Visitor country<select v-model="route.country_code" class="input"><option value="">Default fallback</option><option v-for="country in countries" :key="country.country_code" :value="country.country_code">{{ country.country_code }}</option></select></label>
          <label>Route to edge country<select v-model="route.edge_country_code" class="input"><option value="" disabled>Select edge country</option><option v-for="country in countries" :key="country.country_code" :value="country.country_code">{{ country.country_code }} ({{ country.node_count }} healthy nodes)</option></select></label>
          <button type="button" class="button-secondary" @click="geoRoutes.splice(index,1)">Remove</button>
        </div>
      </div>
      <div class="flex gap-2"><button class="button-primary">Save</button><button type="button" class="button-secondary" @click="editing=false">Cancel</button></div>
      <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>
    </form>
    <DataTable v-else-if="records.length" title="DNS Records" :rows="records" :columns="columns">
      <template #proxied="{row}">{{ row.proxied ? 'Proxied' : 'DNS only' }}</template>
      <template #actions="{row}"><div class="flex gap-2"><button class="button-secondary px-2 py-1 text-xs" @click="edit(row)">Edit</button><ConfirmDangerButton class="px-2 py-1 text-xs" confirm-text="Delete this DNS record?" @confirm="remove(row)">Delete</ConfirmDangerButton></div></template>
    </DataTable>
    <EmptyState v-else title="No DNS records" message="Add a standard, Geo DNS, or proxied CDN record." />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from '@/components/ui/DataTable.vue'; import EmptyState from '@/components/ui/EmptyState.vue'; import ConfirmDangerButton from '@/components/forms/ConfirmDangerButton.vue';
import { dnsApi } from '@/lib/api/dns'; import type { DnsRecord, EdgeCountry, GeoRoute } from '@/types';
const props=defineProps<{domainId:string}>(); const records=ref<DnsRecord[]>([]); const countries=ref<EdgeCountry[]>([]); const geoRoutes=ref<GeoRoute[]>([]);
const editing=ref(false); const editingId=ref(''); const error=ref('');
const form=reactive({type:'A',name:'@',content:'',ttl:300,proxied:false,routing_policy:'standard' as NonNullable<DnsRecord['routing_policy']>});
const columns=[{key:'type',label:'Type'},{key:'name',label:'Name'},{key:'content',label:'Origin / Content'},{key:'proxied',label:'Proxy'},{key:'routing_policy',label:'Routing policy'},{key:'canonical_edge_hostname',label:'Published target'},{key:'geo_routes_count',label:'Geo routes'},{key:'actions',label:'Actions'}];
const isGeo=computed(()=>['geo','geo_anycast'].includes(form.routing_policy)); const publishedPreview=computed(()=>editingId.value?records.value.find(r=>r.id===editingId.value)?.canonical_edge_hostname||'Generated after save':'Generated after save');
async function load(){[records.value,countries.value]=await Promise.all([dnsApi.list(props.domainId),dnsApi.countries()]);}
function startCreate(){editingId.value='';Object.assign(form,{type:'A',name:'@',content:'',ttl:300,proxied:false,routing_policy:'standard'});geoRoutes.value=[];editing.value=true;}
async function edit(value:Record<string,unknown>){const row=value as unknown as DnsRecord;editingId.value=row.id;Object.assign(form,{type:row.type,name:row.name,content:row.content,ttl:row.ttl||300,proxied:!!row.proxied,routing_policy:row.routing_policy||'standard'});geoRoutes.value=isGeo.value?await dnsApi.geoRoutes(props.domainId,row.id):[];editing.value=true;}
function normalizePolicy(){if(!form.proxied&&['anycast','geo_anycast'].includes(form.routing_policy))form.routing_policy=form.routing_policy==='geo_anycast'?'geo':'standard';}
function addGeoRoute(){geoRoutes.value.push({country_code:'',edge_country_code:'',enabled:true});}
async function save(){error.value='';if(isGeo.value&&!geoRoutes.value.some(r=>!r.country_code)){error.value='A default fallback route is required.';return;}if(isGeo.value&&geoRoutes.value.some(r=>!r.edge_country_code)){error.value='Select an edge country for every Geo route.';return;}try{const payload={...form,origin_host:form.proxied?form.content:undefined};const saved=editingId.value?await dnsApi.update(props.domainId,editingId.value,payload):await dnsApi.create(props.domainId,payload);if(isGeo.value)await dnsApi.updateGeoRoutes(props.domainId,saved.id,geoRoutes.value);editing.value=false;await load();}catch(e){error.value=e instanceof Error?e.message:'Unable to save DNS record.';}}
async function remove(value:Record<string,unknown>){const row=value as unknown as DnsRecord;await dnsApi.remove(props.domainId,row.id);await load();} watch(()=>props.domainId,load);onMounted(load);
</script>
