<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

/**
 * The single wrapper every chart goes through.
 *
 * Two jobs: merge the shared theme so no chart can drift, and keep ApexCharts
 * behind one import. The `.client.vue` suffix is load-bearing — ApexCharts
 * touches `window` at import time and would crash the server render otherwise.
 *
 * This is also the swap point: moving to ECharts means rewriting this file and
 * nothing else.
 */
const props = withDefaults(
  defineProps<{
    type: 'bar' | 'donut' | 'radialBar' | 'line' | 'area'
    series: unknown
    options?: ApexOptions
    height?: number
  }>(),
  { height: 240, options: () => ({}) },
)

const merged = computed(() => withBase(props.options ?? {}))
</script>

<template>
  <apexchart
    :type="props.type"
    :height="props.height"
    :series="props.series"
    :options="merged"
  />
</template>
