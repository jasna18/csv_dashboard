<script setup lang="ts">
/**
 * Downloads the current dashboard slice as a workbook.
 *
 * Takes the same query the dashboard is displaying, so an export always matches
 * what was on screen rather than silently exporting everything. Plain anchors
 * rather than a fetch-and-blob: the endpoint already sets Content-Disposition,
 * so the browser handles the save, the file never passes through JS, and a large
 * export streams instead of buffering in memory.
 */
const props = defineProps<{
  /**
   * The dashboard's active filters, already serialised. Taking the string the
   * dashboard itself fetched with is what guarantees the export matches the
   * screen — rebuilding it here would be a second place for the array syntax to
   * drift.
   */
  query: string
  disabled?: boolean
}>()

const config = useRuntimeConfig()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

function url(format: 'csv' | 'xlsx') {
  const separator = props.query ? '&' : ''
  return `${config.public.apiBase}/reliability/export?${props.query}${separator}format=${format}`
}

// Close on an outside click or Escape, the two things a reader expects of a menu.
function onDocumentClick(event: MouseEvent) {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false
}
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-40"
      :disabled="disabled"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="open = !open"
    >
      Export CSV/Excel
      <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m3 4.5 3 3 3-3" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 z-40 mt-1 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg"
      role="menu"
    >
      <a
        :href="url('xlsx')"
        class="block px-3 py-2 text-xs text-slate-700 transition-colors hover:bg-slate-50"
        role="menuitem"
        @click="open = false"
      >
        <span class="font-medium text-slate-900">Excel workbook</span>
        <span class="mt-0.5 block text-slate-500">.xlsx · one sheet per widget</span>
      </a>
      <a
        :href="url('csv')"
        class="block border-t border-slate-100 px-3 py-2 text-xs text-slate-700 transition-colors hover:bg-slate-50"
        role="menuitem"
        @click="open = false"
      >
        <span class="font-medium text-slate-900">CSV</span>
        <span class="mt-0.5 block text-slate-500">.csv · every section in one file</span>
      </a>
      <p class="border-t border-slate-100 bg-slate-50 px-3 py-2 text-[11px] leading-snug text-slate-500">
        Exports the slice currently on screen, filters included.
      </p>
    </div>
  </div>
</template>
