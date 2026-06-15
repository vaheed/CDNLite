import { defineComponent, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useVisibilityPolling } from './polling';

describe('useVisibilityPolling', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  });

  it('polls on the interval while enabled', async () => {
    const refresh = vi.fn();
    let intervalCallback: (() => void) | undefined;
    vi.spyOn(window, 'setInterval').mockImplementation((callback: Parameters<typeof window.setInterval>[0]) => {
      intervalCallback = callback as () => void;
      return 1 as unknown as ReturnType<typeof window.setInterval>;
    });
    vi.spyOn(window, 'clearInterval').mockImplementation(() => undefined);
    const Harness = defineComponent({
      setup() {
        useVisibilityPolling(refresh, 1000);
        return () => null;
      },
    });

    const wrapper = mount(Harness);
    intervalCallback?.();
    await Promise.resolve();
    intervalCallback?.();
    await Promise.resolve();
    await nextTick();

    expect(refresh).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });

  it('runs when the tab becomes visible again', async () => {
    const refresh = vi.fn();
    let intervalCallback: (() => void) | undefined;
    vi.spyOn(window, 'setInterval').mockImplementation((callback: Parameters<typeof window.setInterval>[0]) => {
      intervalCallback = callback as () => void;
      return 1 as unknown as ReturnType<typeof window.setInterval>;
    });
    vi.spyOn(window, 'clearInterval').mockImplementation(() => undefined);
    const Harness = defineComponent({
      setup() {
        useVisibilityPolling(refresh, 1000);
        return () => null;
      },
    });
    const wrapper = mount(Harness);
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });

    intervalCallback?.();
    await nextTick();
    expect(refresh).not.toHaveBeenCalled();

    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
    await nextTick();

    expect(refresh).toHaveBeenCalledOnce();
    wrapper.unmount();
  });
});
