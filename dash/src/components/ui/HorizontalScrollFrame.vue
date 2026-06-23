<template>
  <div class="relative">
    <div
      v-if="showControls && (canScrollLeft || canScrollRight)"
      class="flex items-center justify-end gap-2 border-b border-slate-200 bg-slate-50/50 px-3 py-2 dark:border-white/10 dark:bg-white/[0.015]"
    >
      <button
        type="button"
        class="icon-button h-8 w-8"
        :disabled="!canScrollLeft"
        aria-label="Scroll left"
        @click="scrollByDirection(-1)"
      >
        <ChevronLeft class="h-4 w-4" />
      </button>
      <button
        type="button"
        class="icon-button h-8 w-8"
        :disabled="!canScrollRight"
        aria-label="Scroll right"
        @click="scrollByDirection(1)"
      >
        <ChevronRight class="h-4 w-4" />
      </button>
    </div>
    <div
      ref="scroller"
      class="overflow-x-auto"
      @scroll="updateState"
    >
      <slot />
    </div>
    <div
      v-if="canScrollLeft"
      :class="showControls ? 'top-12' : 'top-0'"
      class="pointer-events-none absolute bottom-0 left-0 w-10 bg-gradient-to-r from-white to-transparent dark:from-slate-950"
    />
    <div
      v-if="canScrollRight"
      :class="showControls ? 'top-12' : 'top-0'"
      class="pointer-events-none absolute bottom-0 right-0 w-10 bg-gradient-to-l from-white to-transparent dark:from-slate-950"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{ watchKey?: unknown; controls?: boolean }>();
const scroller = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);
const showControls = computed(() => props.controls !== false);
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
    if (scroller.value.firstElementChild instanceof HTMLElement) {
      resizeObserver.observe(scroller.value.firstElementChild);
    }
  }
  window.addEventListener('resize', updateState);
});
onBeforeUnmount(() => {
  resizeObserver?.disconnect();
  window.removeEventListener('resize', updateState);
});
watch(() => props.watchKey, () => void nextTick(updateState), { deep: true });
</script>
