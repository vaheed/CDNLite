<template>
  <div class="relative">
    <div
      ref="scroller"
      class="overflow-x-auto"
      @scroll="updateState"
    >
      <slot />
    </div>
    <button
      v-if="canScrollLeft"
      type="button"
      class="absolute left-2 top-1/2 z-10 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white/90 text-slate-600 shadow-sm backdrop-blur transition hover:bg-white focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:border-white/10 dark:bg-slate-950/90 dark:text-slate-200"
      aria-label="Scroll left"
      @click="scrollByDirection(-1)"
    >
      <ChevronLeft class="h-4 w-4" />
    </button>
    <button
      v-if="canScrollRight"
      type="button"
      class="absolute right-2 top-1/2 z-10 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white/90 text-slate-600 shadow-sm backdrop-blur transition hover:bg-white focus:outline-none focus:ring-4 focus:ring-cyan-500/20 dark:border-white/10 dark:bg-slate-950/90 dark:text-slate-200"
      aria-label="Scroll right"
      @click="scrollByDirection(1)"
    >
      <ChevronRight class="h-4 w-4" />
    </button>
    <div
      v-if="canScrollLeft"
      class="pointer-events-none absolute inset-y-0 left-0 w-14 bg-gradient-to-r from-white to-transparent dark:from-slate-950"
    />
    <div
      v-if="canScrollRight"
      class="pointer-events-none absolute inset-y-0 right-0 w-14 bg-gradient-to-l from-white to-transparent dark:from-slate-950"
    />
  </div>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{ watchKey?: unknown }>();
const scroller = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);
let resizeObserver: ResizeObserver | undefined;

function updateState() {
  const el = scroller.value;
  if (!el) return;
  canScrollLeft.value = el.scrollLeft > 1;
  canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

function scrollByDirection(direction: -1 | 1) {
  const el = scroller.value;
  if (!el) return;
  el.scrollBy({ left: direction * Math.max(240, el.clientWidth * 0.75), behavior: 'smooth' });
}

onMounted(() => {
  void nextTick(updateState);
  if (scroller.value && 'ResizeObserver' in window) {
    resizeObserver = new ResizeObserver(updateState);
    resizeObserver.observe(scroller.value);
  }
  window.addEventListener('resize', updateState);
});
onBeforeUnmount(() => {
  resizeObserver?.disconnect();
  window.removeEventListener('resize', updateState);
});
watch(() => props.watchKey, () => void nextTick(updateState), { deep: true });
</script>
