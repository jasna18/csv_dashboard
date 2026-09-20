import VueApexCharts from 'vue3-apexcharts'

/**
 * Registers the <apexchart> component.
 *
 * The `.client.ts` suffix is mandatory, not stylistic: ApexCharts reads `window`
 * at import time, so pulling it into the server bundle crashes the render before
 * a single chart mounts. Anything importing it must also be client-only —
 * `BaseChart.client.vue` is the one place that does.
 */
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.use(VueApexCharts)
})
