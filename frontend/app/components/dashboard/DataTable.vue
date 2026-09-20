<script setup lang="ts">
/**
 * The table twin behind every chart's "table" toggle.
 *
 * This is the WCAG-clean equivalent of the chart above it, so it is a real
 * table with real headers — not a styled grid of divs. `tabular-nums` belongs
 * here, where digits stack into columns, and nowhere near the big standalone
 * figures on the stat tiles.
 */
defineProps<{
  columns: { key: string, label: string, numeric?: boolean }[]
  rows: Record<string, string | number>[]
}>()
</script>

<template>
  <table class="w-full text-xs">
    <thead>
      <tr class="border-b border-slate-200 text-left text-slate-500">
        <th
          v-for="column in columns"
          :key="column.key"
          scope="col"
          class="py-1.5 pr-3 font-medium"
          :class="column.numeric ? 'text-right' : ''"
        >
          {{ column.label }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="(row, index) in rows"
        :key="index"
        class="border-b border-slate-100 last:border-0"
      >
        <td
          v-for="column in columns"
          :key="column.key"
          class="py-1.5 pr-3 text-slate-700"
          :class="column.numeric ? 'text-right tabular-nums' : ''"
        >
          {{ row[column.key] }}
        </td>
      </tr>
      <tr v-if="!rows.length">
        <td :colspan="columns.length" class="py-6 text-center text-slate-500">
          No rows in this slice.
        </td>
      </tr>
    </tbody>
  </table>
</template>
