<template>
  <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.20),transparent_38%),radial-gradient(circle_at_bottom_right,rgba(99,102,241,0.15),transparent_35%)]">
    <div class="flex min-h-screen">
      <Sidebar />
      <div class="min-w-0 flex-1">
        <TopStatusBar />
        <main class="mx-auto max-w-7xl space-y-6 p-4 lg:p-6">
          <RouterView />
        </main>
      </div>
    </div>
    <CommandPalette />
  </div>
</template>
<script setup lang="ts">
import Sidebar from './Sidebar.vue';
import TopStatusBar from './TopStatusBar.vue';
import CommandPalette from './CommandPalette.vue';
</script>
