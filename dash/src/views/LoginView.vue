<template>
  <main class="app-surface flex min-h-screen items-center justify-center p-4">
    <section class="app-panel w-full max-w-md rounded-lg p-6">
      <div class="mb-6">
        <p class="text-sm font-semibold uppercase text-cyan-700 dark:text-cyan-300">{{ runtimeConfig.appName }}</p>
        <h1 class="mt-2 text-2xl font-black text-slate-950 dark:text-white">Admin login</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Sign in with a dashboard admin account.</p>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <TextInput
          v-model="username"
          :help="{ label: 'Username', what: 'Admin account username.', works: 'Created with php artisan cdn:admin:create.', example: 'admin', required: true }"
        />
        <TextInput
          v-model="password"
          type="password"
          :help="{ label: 'Password', what: 'Admin account password.', works: 'Creates an in-memory dashboard session token.', example: 'Use your CLI-created password.', required: true }"
        />
        <p v-if="auth.error" class="rounded border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ auth.error }}</p>
        <button class="button-primary w-full justify-center" type="submit" :disabled="auth.loading">
          {{ auth.loading ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>

      <p class="mt-5 text-xs leading-5 text-slate-500 dark:text-slate-400">
        Local quickstart uses <code>admin</code> / <code>admin</code> when bootstrap admin is enabled. Session tokens stay in browser memory only.
      </p>
    </section>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import TextInput from '@/components/forms/TextInput.vue';
import { runtimeConfig } from '@/lib/config/env';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const username = ref('');
const password = ref('');

async function submit() {
  await auth.login(username.value, password.value);
}
</script>
