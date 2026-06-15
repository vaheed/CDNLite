import { onBeforeUnmount, watch, type WatchSource } from 'vue';

interface PollingOptions {
  enabled?: () => boolean;
  immediate?: boolean;
}

export function useVisibilityPolling(
  refresh: () => void | Promise<void>,
  intervalMs: number | WatchSource<number>,
  options: PollingOptions = {},
) {
  let timer: number | undefined;
  let running = false;

  const isVisible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden';
  const isEnabled = () => (options.enabled ? options.enabled() : true);
  const currentInterval = () => typeof intervalMs === 'function' ? Number(intervalMs()) : Number(intervalMs);

  async function run() {
    if (running || !isVisible() || !isEnabled()) return;
    running = true;
    try {
      await refresh();
    } finally {
      running = false;
    }
  }

  function stop() {
    if (timer !== undefined && typeof window !== 'undefined') window.clearInterval(timer);
    timer = undefined;
  }

  function start() {
    stop();
    if (typeof window === 'undefined') return;
    const delay = currentInterval();
    if (!Number.isFinite(delay) || delay <= 0) return;
    timer = window.setInterval(run, delay);
  }

  function onVisibilityChange() {
    if (isVisible() && isEnabled()) void run();
  }

  start();
  if (options.immediate) void run();
  if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisibilityChange);
  if (typeof intervalMs === 'function') watch(intervalMs, start);
  onBeforeUnmount(() => {
    stop();
    if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisibilityChange);
  });

  return { refreshNow: run, restart: start, stop };
}
