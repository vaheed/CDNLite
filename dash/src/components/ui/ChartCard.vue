<template>
  <div class="card p-4">
    <div class="mb-4">
      <h3 class="font-semibold text-slate-950 dark:text-white">{{ title }}</h3>
      <p v-if="subtitle" class="text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
    </div>
    <VChart class="h-72 w-full" :option="themedOption" autoresize />
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart, PieChart, GaugeChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import VChart from 'vue-echarts';
import { useUiStore } from '@/stores/ui';
use([CanvasRenderer, BarChart, LineChart, PieChart, GaugeChart, GridComponent, TooltipComponent, LegendComponent]);
const props = defineProps<{ title: string; subtitle?: string; option: Record<string, unknown> }>();
const ui = useUiStore();
const themedOption = computed(() => {
  const text = ui.darkMode ? '#f8fafc' : '#0f172a';
  const muted = ui.darkMode ? '#94a3b8' : '#475569';
  const border = ui.darkMode ? 'rgba(255,255,255,0.12)' : '#e2e8f0';
  const option = { ...props.option };
  const axisDefaults = { axisLabel: { color: muted }, axisLine: { lineStyle: { color: border } }, splitLine: { lineStyle: { color: border } } };
  const series = Array.isArray(option.series) ? option.series.map((item) => {
    if (item && typeof item === 'object' && (item as { type?: string }).type === 'pie') {
      return { ...(item as Record<string, unknown>), avoidLabelOverlap: true, label: { show: false }, labelLine: { show: false } };
    }
    return { label: { color: text }, ...(item as Record<string, unknown>) };
  }) : option.series;
  return {
    ...option,
    color: ['#2563eb', '#16a34a', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'],
    textStyle: { color: text },
    tooltip: { backgroundColor: ui.darkMode ? '#111827' : '#ffffff', borderColor: border, textStyle: { color: text }, ...(option.tooltip as Record<string, unknown> ?? {}) },
    legend: { textStyle: { color: muted }, ...(option.legend as Record<string, unknown> ?? {}) },
    xAxis: option.xAxis ? { ...axisDefaults, ...(option.xAxis as Record<string, unknown>) } : option.xAxis,
    yAxis: option.yAxis ? { ...axisDefaults, ...(option.yAxis as Record<string, unknown>) } : option.yAxis,
    series,
  };
});
</script>
