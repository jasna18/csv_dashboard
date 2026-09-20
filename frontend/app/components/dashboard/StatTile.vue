<script setup lang="ts">
/**
 * One headline KPI.
 *
 * A stat tile, not a one-bar bar chart — when the data is a single current
 * value, the number *is* the chart. `sub` carries the figure that stops the
 * headline being read wrong (a median beside a skewed mean, the runtime a
 * percentage is a share of).
 *
 * The status indicator is a dot **and** a word. Status colour never carries the
 * meaning by itself, which is what keeps the tile readable for a colour-blind
 * reader and in greyscale print.
 */
withDefaults(
  defineProps<{
    label: string
    value: string
    sub?: string
    /** Left rule colour. Identity only — it never encodes the value. */
    accent?: 'blue' | 'green'
    /** Expands into the tooltip and the screen-reader description. */
    definition?: string
    status?: { label: string, color: string } | null
  }>(),
  { accent: 'blue', status: null },
)
</script>

<template>
  <div
    class="min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm border-l-4"
    :class="accent === 'green' ? 'border-l-fleet-500' : 'border-l-brand-500'"
  >
    <p class="flex items-center gap-1 text-xs font-medium text-slate-500">
      <span class="truncate">{{ label }}</span>
      <span
        v-if="definition"
        class="shrink-0 cursor-help text-slate-400"
        :title="definition"
        :aria-label="`${label}: ${definition}`"
      >&#9432;</span>
    </p>

    <!-- Proportional figures on purpose: tabular-nums makes a large standalone
         number look loosely spaced. -->
    <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
      {{ value }}
    </p>

    <p v-if="status" class="mt-1 flex items-center gap-1.5 text-xs font-medium">
      <span
        class="h-2 w-2 shrink-0 rounded-full"
        :style="{ backgroundColor: status.color }"
        aria-hidden="true"
      />
      <span :style="{ color: status.color }">{{ status.label }}</span>
    </p>

    <p v-if="sub" class="mt-0.5 text-xs text-slate-500">
      {{ sub }}
    </p>
  </div>
</template>
