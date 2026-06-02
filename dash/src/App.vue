<template>
  <AppShell v-if="auth.isAuthenticated" />
  <LoginView v-else />
</template>

<script setup lang="ts">
import AppShell from '@/components/layout/AppShell.vue';
import LoginView from '@/views/LoginView.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
</script>
