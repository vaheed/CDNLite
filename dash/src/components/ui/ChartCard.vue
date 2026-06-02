<template>
  <div class="card p-4">
    <div class="mb-4">
      <h3 class="font-semibold text-white">{{ title }}</h3>
      <p v-if="subtitle" class="text-sm text-slate-400">{{ subtitle }}</p>
    </div>
    <VChart class="h-72 w-full" :option="option" autoresize />
  </div>
</template>
<script setup lang="ts">
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart, PieChart, GaugeChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import VChart from 'vue-echarts';
use([CanvasRenderer, BarChart, LineChart, PieChart, GaugeChart, GridComponent, TooltipComponent, LegendComponent]);
defineProps<{ title: string; subtitle?: string; option: Record<string, unknown> }>();
</script>
