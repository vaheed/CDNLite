<template>
  <div class="pointer-events-none fixed right-4 top-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3">
    <div
      v-for="item in notifications"
      :key="item.id"
      class="pointer-events-auto rounded-lg border bg-white p-4 shadow-lg dark:bg-slate-950"
      :class="kindClass(item.kind)"
      role="status"
    >
      <div class="flex items-start gap-3">
        <div class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="dotClass(item.kind)" />
        <div class="min-w-0 flex-1">
          <p class="font-semibold text-slate-950 dark:text-white">{{ item.title }}</p>
          <p v-if="item.message" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ item.message }}</p>
        </div>
        <button class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-100" type="button" aria-label="Dismiss notification" @click="dismissNotification(item.id)">×</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useNotifications, type NotificationKind } from '@/lib/ui/notifications';

const { notifications, dismissNotification } = useNotifications();

function kindClass(kind: NotificationKind) {
  return {
    success: 'border-emerald-200 dark:border-emerald-400/30',
    error: 'border-rose-200 dark:border-rose-400/30',
    info: 'border-cyan-200 dark:border-cyan-400/30',
  }[kind];
}

function dotClass(kind: NotificationKind) {
  return {
    success: 'bg-emerald-500',
    error: 'bg-rose-500',
    info: 'bg-cyan-500',
  }[kind];
}
</script>
