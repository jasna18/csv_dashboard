<script setup lang="ts">
/**
 * Card chrome for one dashboard widget.
 *
 * The reference layout puts a control cluster in every card header. Two of those
 * are worth keeping and one is not:
 *
 *  - a per-card *filter* is not: nine cards each with their own dropdown means
 *    nine slices on screen at once and no way to tell which card is showing
 *    what. Filtering lives in one row above the grid, scoping everything.
 *  - a chart/table toggle is: several series colours sit below 3:1 against
 *    white, and the documented relief for that is a readable table of the same
 *    numbers. It doubles as the keyboard- and screen-reader-friendly view, so
 *    it is on every widget rather than the ones that happen to need it.
 */
withDefaults(
  defineProps<{
    title: string
    /** One line under the title — what the widget measures, or its caveat. */
    hint?: string
    /** Wider cards for charts with long category labels. */
    span?: 1 | 2
    /** Hides the toggle for widgets whose table twin would say nothing extra. */
    tabular?: boolean
    loading?: boolean
  }>(),
  { span: 1, tabular: true, loading: false },
)

const view = ref<'chart' | 'table'>('chart')
</script>

<template>
  <section
    class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm transition-shadow hover:shadow-md"
    :class="span === 2 ? 'lg:col-span-2' : ''"
  >
    <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
      <div class="min-w-0">
        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <span class="h-2 w-2 shrink-0 rounded-full bg-brand-500" aria-hidden="true" />
          <span class="truncate">{{ title }}</span>
        </h3>
        <p v-if="hint" class="mt-0.5 text-xs text-slate-500">
          {{ hint }}
        </p>
      </div>

      <div v-if="tabular" class="flex shrink-0 rounded-md border border-slate-200 p-0.5" role="group" :aria-label="`${title} view`">
        <button
          v-for="option in (['chart', 'table'] as const)"
          :key="option"
          type="button"
          class="rounded px-2 py-1 text-xs font-medium capitalize transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
          :class="view === option
            ? 'bg-brand-50 text-brand-700'
            : 'text-slate-500 hover:text-slate-800'"
          :aria-pressed="view === option"
          @click="view = option"
        >
          {{ option }}
        </button>
      </div>
    </header>

    <!-- Hold the previous render at reduced opacity on refetch. A skeleton here
         would collapse the card and bounce the whole grid on every filter change. -->
    <div
      class="flex-1 px-2 pb-3 pt-2 transition-opacity duration-200"
      :class="loading ? 'opacity-40' : 'opacity-100'"
      :aria-busy="loading"
    >
      <slot v-if="view === 'chart'" />
      <div v-else class="max-h-72 overflow-auto px-2">
        <slot name="table">
          <p class="py-6 text-center text-xs text-slate-500">No table view for this widget.</p>
        </slot>
      </div>
    </div>
  </section>
</template>
