<template>
  <main class="app-surface grid min-h-screen lg:grid-cols-[1.1fr_0.9fr]">
    <section class="relative hidden overflow-hidden bg-slate-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
      <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(6,182,212,0.18),transparent_38%),radial-gradient(circle_at_80%_75%,rgba(37,99,235,0.16),transparent_40%)]" />
      <div class="relative flex items-center gap-3"><div class="grid h-11 w-11 place-items-center rounded-xl bg-cyan-400 font-black text-slate-950">CDN</div><div><p class="font-bold">{{ runtimeConfig.appName }}</p><p class="text-xs text-slate-400">Operations console</p></div></div>
      <div class="relative max-w-xl">
        <p class="text-sm font-bold uppercase tracking-[0.2em] text-cyan-300">Control your delivery edge</p>
        <h1 class="mt-5 text-5xl font-black leading-tight tracking-tight">Operate traffic, security, and delivery with confidence.</h1>
        <p class="mt-6 text-lg leading-8 text-slate-300">A focused control plane for domains, edge health, cache behavior, certificates, and security events.</p>
        <div class="mt-10 grid grid-cols-3 gap-4 text-sm">
          <div class="rounded-xl border border-white/10 bg-white/5 p-4"><b class="block text-cyan-300">Observe</b><span class="mt-1 block text-slate-400">Live operational health</span></div>
          <div class="rounded-xl border border-white/10 bg-white/5 p-4"><b class="block text-cyan-300">Protect</b><span class="mt-1 block text-slate-400">WAF and rate limits</span></div>
          <div class="rounded-xl border border-white/10 bg-white/5 p-4"><b class="block text-cyan-300">Deliver</b><span class="mt-1 block text-slate-400">DNS, SSL, and cache</span></div>
        </div>
      </div>
      <p class="relative text-xs text-slate-500">Administrative access is audited by the CDNLite control plane.</p>
    </section>
    <section class="flex items-center justify-center p-5 sm:p-10">
      <div class="w-full max-w-md">
        <div class="mb-8 flex items-center gap-3 lg:hidden"><div class="grid h-10 w-10 place-items-center rounded-lg bg-cyan-600 text-sm font-black text-white">CDN</div><b>{{ runtimeConfig.appName }}</b></div>
        <div class="card rounded-2xl p-6 shadow-xl sm:p-8">
          <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700 dark:text-cyan-300">Secure administration</p>
          <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white">Welcome back</h2>
          <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Sign in to manage your CDNLite deployment.</p>
          <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-400"><b class="text-slate-800 dark:text-slate-200">Core API:</b> {{ runtimeConfig.coreUrl }}</div>
          <form class="mt-6 space-y-5" novalidate @submit.prevent="submit">
            <TextInput v-model="username" autofocus :help="{ label: 'Username', what: 'Your dashboard administrator account.', works: 'Accounts are created from the Core CLI.', example: 'admin', required: true, error: errors.username }" />
            <div>
              <TextInput v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" :help="{ label: 'Password', what: 'The password for this administrator account.', works: 'It is exchanged for a tab-scoped session token and is not stored.', example: 'Your administrator password', required: true, error: errors.password }" />
              <button type="button" class="mt-2 text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300" :aria-pressed="showPassword" @click="showPassword = !showPassword">{{ showPassword ? 'Hide password' : 'Show password' }}</button>
            </div>
            <div v-if="auth.error" class="state-error" role="alert"><b>Sign-in failed.</b> {{ auth.error }}</div>
            <button class="button-primary min-h-11 w-full" type="submit" :disabled="auth.loading">{{ auth.loading ? 'Signing in…' : 'Sign in securely' }}</button>
          </form>
          <p class="mt-5 text-xs leading-5 text-slate-500 dark:text-slate-400">Your session token is kept in memory for this browser tab. Closing the tab ends the local session.</p>
          <details class="mt-5 border-t border-slate-200 pt-4 text-sm dark:border-white/10"><summary class="cursor-pointer font-semibold text-slate-700 dark:text-slate-300">Local development help</summary><p class="mt-3 text-slate-500 dark:text-slate-400">When bootstrap admin is enabled, the local quickstart uses <code>admin</code> / <code>admin</code>. Never use these credentials outside local development.</p></details>
        </div>
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'; import TextInput from '@/components/forms/TextInput.vue'; import { runtimeConfig } from '@/lib/config/env'; import { useAuthStore } from '@/stores/auth';
const auth = useAuthStore(); const username = ref(''); const password = ref(''); const showPassword = ref(false); const errors = reactive({ username: '', password: '' });
async function submit() { errors.username = username.value.trim() ? '' : 'Username is required.'; errors.password = password.value ? '' : 'Password is required.'; if (errors.username || errors.password) return; await auth.login(username.value.trim(), password.value); }
</script>
