<template>
  <section class="space-y-6">
    <PageHeader title="Config Snapshots" description="Inspect, compare, activate, and rebuild edge configuration versions." eyebrow="Operations">
      <button class="button-primary" :disabled="busy" @click="rebuild">Rebuild</button>
    </PageHeader>
    <div class="grid gap-4 md:grid-cols-3">
      <div class="card p-5"><p class="text-sm text-slate-500">Snapshots</p><p class="mt-2 text-3xl font-black">{{ snapshots.length }}</p></div>
      <div class="card p-5"><p class="text-sm text-slate-500">Active version</p><p class="mt-2 text-3xl font-black">{{ activeSnapshot ? `v${activeSnapshot.version}` : 'None' }}</p></div>
      <div class="card p-5"><p class="text-sm text-slate-500">Latest generated</p><p class="mt-2 text-lg font-bold">{{ latestSnapshot ? formatDate(latestSnapshot.generated_at) : 'None' }}</p></div>
    </div>
    <div v-if="error" class="card p-4 text-red-700">{{ error }}</div>
    <div class="card overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
        <div><h2 class="font-semibold tracking-tight text-slate-950 dark:text-white">Snapshot History</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Select two versions to compare or activate a known-good version.</p></div>
        <button class="button-secondary" :disabled="selectedVersions.length !== 2 || busy" @click="compare">Compare selected</button>
      </div>
      <HorizontalScrollFrame :watch-key="snapshots.length">
      <table class="w-full min-w-[760px] text-left text-sm">
        <thead class="table-head"><tr><th>Compare</th><th>Version</th><th>Generated</th><th>Hash</th><th>Size</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
          <tr v-for="snapshot in snapshots" :key="snapshot.version" class="border-b last:border-0 dark:border-white/5">
            <td class="table-cell"><input v-model="selectedVersions" type="checkbox" :value="snapshot.version" :disabled="!selectedVersions.includes(snapshot.version) && selectedVersions.length >= 2" /></td>
            <td class="table-cell font-bold">v{{ snapshot.version }} <span v-if="snapshot.active" class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-200">Active</span></td>
            <td class="table-cell whitespace-nowrap">{{ formatDate(snapshot.generated_at) }}</td><td class="table-cell font-mono text-xs">{{ snapshot.content_hash.slice(0, 12) }}</td><td class="table-cell">{{ formatSize(snapshot.size) }}</td>
            <td class="table-cell text-right"><div class="flex justify-end gap-2"><button class="button-secondary px-3 py-1.5 text-xs" @click="view(snapshot.version)">View</button><button class="button-secondary px-3 py-1.5 text-xs" :disabled="busy || snapshot.active" @click="rollback(snapshot.version)">Rollback</button></div></td>
          </tr>
          <tr v-if="snapshots.length === 0"><td colspan="6" class="px-5 py-14 text-center text-sm text-slate-500">No snapshots generated yet.</td></tr>
        </tbody>
      </table>
      </HorizontalScrollFrame>
    </div>
    <DetailsDrawer :open="detail !== null" title="Snapshot JSON" @close="detail=null"><pre class="overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-white">{{ json(detail) }}</pre></DetailsDrawer>
    <DetailsDrawer :open="diff !== null" title="Snapshot diff" @close="diff=null">
      <div v-if="diff" class="space-y-4">
        <p class="font-bold text-slate-950 dark:text-white">v{{ diff.from_version }} to v{{ diff.to_version }}</p>
        <div v-for="change in diff.changes" :key="change.path" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-950">
          <code class="break-all rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ change.path }}</code>
          <div class="mt-3 grid gap-3 lg:grid-cols-2">
            <section class="overflow-hidden rounded-lg border border-red-200 bg-red-50 dark:border-red-400/20 dark:bg-red-400/10">
              <h3 class="border-b border-red-200 px-3 py-2 text-xs font-bold uppercase text-red-800 dark:border-red-400/20 dark:text-red-200">Before</h3>
              <pre class="max-h-80 overflow-auto p-3 text-xs leading-5 text-red-950 dark:text-red-50">{{ json(change.before) }}</pre>
            </section>
            <section class="overflow-hidden rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-400/20 dark:bg-emerald-400/10">
              <h3 class="border-b border-emerald-200 px-3 py-2 text-xs font-bold uppercase text-emerald-800 dark:border-emerald-400/20 dark:text-emerald-200">After</h3>
              <pre class="max-h-80 overflow-auto p-3 text-xs leading-5 text-emerald-950 dark:text-emerald-50">{{ json(change.after) }}</pre>
            </section>
          </div>
        </div>
        <p v-if="!diff.changes.length" class="rounded-lg border border-slate-200 p-4 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">No differences.</p>
      </div>
    </DetailsDrawer>
  </section>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DetailsDrawer from '@/components/ui/DetailsDrawer.vue';
import HorizontalScrollFrame from '@/components/ui/HorizontalScrollFrame.vue';
import { configSnapshotsApi } from '@/lib/api/configSnapshots';
import { formatDate } from '@/lib/utils/format';
import type { ConfigSnapshot, ConfigSnapshotDiff, ConfigSnapshotSummary } from '@/types';
const snapshots=ref<ConfigSnapshotSummary[]>([]),selectedVersions=ref<number[]>([]),detail=ref<ConfigSnapshot|null>(null),diff=ref<ConfigSnapshotDiff|null>(null),busy=ref(false),error=ref('');
const activeSnapshot=computed(()=>snapshots.value.find((snapshot)=>snapshot.active));
const latestSnapshot=computed(()=>[...snapshots.value].sort((a,b)=>b.generated_at-a.generated_at)[0]);
const json=(value:unknown)=>JSON.stringify(value,null,2);
const formatSize=(bytes:number)=>bytes<1024?`${bytes} B`:`${(bytes/1024).toFixed(1)} KB`;
async function load(){snapshots.value=await configSnapshotsApi.list();}
async function run(action:()=>Promise<void>){busy.value=true;error.value='';try{await action();}catch(e){error.value=e instanceof Error?e.message:'Request failed.';}finally{busy.value=false;}}
async function view(version:number){await run(async()=>{detail.value=await configSnapshotsApi.get(version);});}
async function compare(){await run(async()=>{const versions=[...selectedVersions.value].sort((a,b)=>a-b);diff.value=await configSnapshotsApi.diff(versions[0],versions[1]);});}
async function rollback(version:number){if(!window.confirm(`Activate config snapshot v${version}?`))return;await run(async()=>{await configSnapshotsApi.rollback(version);await load();});}
async function rebuild(){await run(async()=>{await configSnapshotsApi.rebuild();selectedVersions.value=[];await load();});}
onMounted(()=>run(load));
</script>
