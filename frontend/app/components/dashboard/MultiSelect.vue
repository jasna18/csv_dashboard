<script setup lang="ts">
/**
 * A dropdown of checkboxes for one filter dimension.
 *
 * Selecting nothing means "all" rather than "none" — an empty filter is the
 * absence of a constraint, and a dashboard that shows nothing until you tick
 * something is a worse default than one that shows everything. The "All" row is
 * therefore a shortcut that clears the selection, not a value of its own, and it
 * reads as checked exactly when the selection is empty.
 *
 * The search box appears only once a list is long enough to need it; Equipment
 * has 90 options and the rest have fewer than ten.
 */
const props = withDefaults(
  defineProps<{
    label: string
    /** Selected values. Empty means every value. */
    modelValue: string[]
    options: string[]
    /** Shown on the "All" row and as the summary when nothing is selected. */
    allLabel?: string
    /** Renders an option for display without changing the value sent to the API. */
    format?: (value: string) => string
    searchThreshold?: number
  }>(),
  { allLabel: 'All', searchThreshold: 12, format: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [string[]] }>()

const open = ref(false)
const search = ref('')
const root = ref<HTMLElement | null>(null)

/**
 * The selection, held locally and mirrored out.
 *
 * Deriving each toggle from `props.modelValue` looked simpler but dropped
 * clicks: the prop only updates after the parent re-renders, so two checkboxes
 * ticked in the same tick both read the old value and the second emit discarded
 * the first. Ticking January then March sent only March. Local state is updated
 * synchronously, so every click builds on the one before it.
 */
const selected = ref<string[]>([...props.modelValue])

watch(() => props.modelValue, (value) => {
  // Cheap guard against the echo of our own emit re-entering as a new array.
  if (value.join('\u0000') !== selected.value.join('\u0000')) {
    selected.value = [...value]
  }
})

const display = (value: string) => (props.format ? props.format(value) : value)

const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.options
  return props.options.filter(option => display(option).toLowerCase().includes(term))
})

const summary = computed(() => {
  const count = selected.value.length
  if (count === 0) return props.allLabel
  if (count === 1) return display(selected.value[0]!)
  return `${count} selected`
})

function toggle(value: string) {
  selected.value = selected.value.includes(value)
    ? selected.value.filter(item => item !== value)
    : [...selected.value, value]

  emit('update:modelValue', [...selected.value])
}

/** Clearing is what "All" means; there is no value to select. */
function selectAll() {
  selected.value = []
  emit('update:modelValue', [])
}

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

watch(open, (isOpen) => {
  if (!isOpen) search.value = ''
})

const id = useId()
</script>

<template>
  <div ref="root" class="relative flex min-w-0 flex-col gap-1">
    <span :id="`${id}-label`" class="text-xs font-medium text-slate-500">{{ label }}</span>

    <button
      type="button"
      class="flex w-44 items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-left text-xs transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
      :class="selected.length
        ? 'border-brand-400 bg-brand-50 text-brand-800'
        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
      :aria-expanded="open"
      :aria-labelledby="`${id}-label`"
      aria-haspopup="true"
      @click="open = !open"
    >
      <span class="truncate">{{ summary }}</span>
      <svg class="h-3 w-3 shrink-0 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m3 4.5 3 3 3-3" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute left-0 top-full z-40 mt-1 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg"
    >
      <div v-if="options.length >= searchThreshold" class="border-b border-slate-100 p-2">
        <input
          v-model="search"
          type="search"
          :placeholder="`Search ${label.toLowerCase()}…`"
          class="w-full rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-700 focus:border-brand-500 focus:outline-none"
        >
      </div>

      <div class="max-h-64 overflow-y-auto py-1">
        <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">
          <input
            type="checkbox"
            class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="selected.length === 0"
            @change="selectAll"
          >
          <span>{{ allLabel }}</span>
        </label>

        <div class="my-1 border-t border-slate-100" />

        <label
          v-for="option in visible"
          :key="option"
          class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="selected.includes(option)"
            @change="toggle(option)"
          >
          <span class="truncate">{{ display(option) }}</span>
        </label>

        <p v-if="!visible.length" class="px-3 py-3 text-center text-xs text-slate-500">
          No match.
        </p>
      </div>

      <button
        v-if="selected.length"
        type="button"
        class="w-full border-t border-slate-100 bg-slate-50 px-3 py-2 text-left text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
        @click="selectAll"
      >
        Clear {{ selected.length }} selected
      </button>
    </div>
  </div>
</template>
