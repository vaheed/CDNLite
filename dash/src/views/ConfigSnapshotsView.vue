<template>
  <section class="space-y-6">
    <PageHeader title="Config Snapshots" description="Inspect, compare, activate, and rebuild edge configuration versions." eyebrow="Operations">
      <button class="button-primary" :disabled="busy" @click="rebuild">Rebuild</button>
    </PageHeader>
    <div v-if="error" class="card p-4 text-red-700">{{ error }}</div>
    <div class="card overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead><tr class="border-b"><th class="p-3">Compare</th><th>Version</th><th>Generated</th><th>Hash</th><th>Size</th><th class="p-3">Actions</th></tr></thead>
        <tbody>
          <tr v-for="snapshot in snapshots" :key="snapshot.version" class="border-b last:border-0">
            <td class="p-3"><input v-model="selectedVersions" type="checkbox" :value="snapshot.version" :disabled="!selectedVersions.includes(snapshot.version) && selectedVersions.length >= 2" /></td>
            <td class="font-bold">v{{ snapshot.version }} <span v-if="snapshot.active" class="text-emerald-600">Active</span></td>
            <td>{{ formatDate(snapshot.generated_at) }}</td><td class="font-mono text-xs">{{ snapshot.content_hash.slice(0, 12) }}</td><td>{{ snapshot.size }} B</td>
            <td class="p-3 space-x-2"><button class="button-secondary" @click="view(snapshot.version)">View</button><button class="button-secondary" :disabled="busy || snapshot.active" @click="rollback(snapshot.version)">Rollback</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <button class="button-primary" :disabled="selectedVersions.length !== 2 || busy" @click="compare">Compare selected</button>
    <DetailsDrawer :open="detail !== null" title="Snapshot JSON" @close="detail=null"><pre class="overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ json(detail) }}</pre></DetailsDrawer>
    <DetailsDrawer :open="diff !== null" title="Snapshot diff" @close="diff=null">
      <div v-if="diff" class="space-y-3"><p class="font-bold">v{{ diff.from_version }} to v{{ diff.to_version }}</p><div v-for="change in diff.changes" :key="change.path" class="rounded-lg border p-3"><code>{{ change.path }}</code><pre class="mt-2 overflow-auto text-xs">- {{ json(change.before) }}
+ {{ json(change.after) }}</pre></div><p v-if="!diff.changes.length">No differences.</p></div>
    </DetailsDrawer>
  </section>
</template>
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import { configSnapshotsApi } from '@/lib/api/configSnapshots';
import { formatDate } from '@/lib/utils/format';
import type { ConfigSnapshot, ConfigSnapshotDiff, ConfigSnapshotSummary } from '@/types';
const snapshots=ref<ConfigSnapshotSummary[]>([]),selectedVersions=ref<number[]>([]),detail=ref<ConfigSnapshot|null>(null),diff=ref<ConfigSnapshotDiff|null>(null),busy=ref(false),error=ref('');
const json=(value:unknown)=>JSON.stringify(value,null,2);
async function load(){snapshots.value=await configSnapshotsApi.list();}
async function run(action:()=>Promise<void>){busy.value=true;error.value='';try{await action();}catch(e){error.value=e instanceof Error?e.message:'Request failed.';}finally{busy.value=false;}}
async function view(version:number){await run(async()=>{detail.value=await configSnapshotsApi.get(version);});}
async function compare(){await run(async()=>{const versions=[...selectedVersions.value].sort((a,b)=>a-b);diff.value=await configSnapshotsApi.diff(versions[0],versions[1]);});}
async function rollback(version:number){if(!window.confirm(`Activate config snapshot v${version}?`))return;await run(async()=>{await configSnapshotsApi.rollback(version);await load();});}
async function rebuild(){await run(async()=>{await configSnapshotsApi.rebuild();selectedVersions.value=[];await load();});}
onMounted(()=>run(load));
</script>
