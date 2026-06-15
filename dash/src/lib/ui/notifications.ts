import { computed, reactive } from 'vue';

export type NotificationKind = 'success' | 'error' | 'info';

export interface AppNotification {
  id: number;
  kind: NotificationKind;
  title: string;
  message?: string;
}

const notifications = reactive<AppNotification[]>([]);
let nextId = 1;

export function notify(input: Omit<AppNotification, 'id'>, timeoutMs = 5000) {
  const notification = { ...input, id: nextId++ };
  notifications.push(notification);
  if (timeoutMs > 0 && typeof window !== 'undefined') {
    window.setTimeout(() => dismissNotification(notification.id), timeoutMs);
  }
  return notification.id;
}

export function dismissNotification(id: number) {
  const index = notifications.findIndex((item) => item.id === id);
  if (index >= 0) notifications.splice(index, 1);
}

export function useNotifications() {
  return {
    notifications: computed(() => notifications),
    dismissNotification,
  };
}
