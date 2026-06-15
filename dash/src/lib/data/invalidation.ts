import { onBeforeUnmount } from 'vue';
import { keysForMutation } from './queryKeys';

export const DATA_INVALIDATED_EVENT = 'cdnlite:data-invalidated';
export const CONFIG_PUBLISHING_EVENT = 'cdnlite:config-publishing';

export interface DataInvalidatedDetail {
  keys: string[];
  method: string;
  path: string;
}

export function emitInvalidation(method: string, path: string) {
  if (typeof window === 'undefined') return;
  const keys = keysForMutation(method, path);
  if (keys.length === 0) return;
  window.dispatchEvent(new CustomEvent<DataInvalidatedDetail>(DATA_INVALIDATED_EVENT, {
    detail: { keys, method: method.toUpperCase(), path },
  }));
  if (keys.some((key) => key.startsWith('domain:') || key.startsWith('domain-dns:') || key.startsWith('domain-origins:') || key.startsWith('domain-ssl:'))) {
    window.dispatchEvent(new CustomEvent(CONFIG_PUBLISHING_EVENT));
  }
}

export function useInvalidationListener(keys: () => string[], refresh: () => void | Promise<void>) {
  const onInvalidated = (event: Event) => {
    const detail = (event as CustomEvent<DataInvalidatedDetail>).detail;
    const watched = new Set(keys());
    if (detail.keys.some((key) => watched.has(key))) void refresh();
  };
  window.addEventListener(DATA_INVALIDATED_EVENT, onInvalidated);
  onBeforeUnmount(() => window.removeEventListener(DATA_INVALIDATED_EVENT, onInvalidated));
}
