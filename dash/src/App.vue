<template>
  <main v-if="!auth.initialized" class="app-surface flex min-h-screen items-center justify-center text-sm text-slate-500">
    Restoring admin session...
  </main>
  <AppShell v-else-if="auth.isAuthenticated" />
  <LoginView v-else />
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import AppShell from '@/components/layout/AppShell.vue';
import LoginView from '@/views/LoginView.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
onMounted(() => auth.initialize());
</script>
