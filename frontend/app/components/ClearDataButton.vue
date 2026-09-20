<script setup lang="ts">
/**
 * Navbar action that empties the table.
 *
 * It sits in global navigation, one click from every page, so the confirm is a
 * modal rather than the inline two-step used on the import page: a destructive
 * control that travels with the chrome should interrupt, not sit quietly beside
 * the links it resembles. It is styled as a hazard so it never reads as another
 * nav item, and it is disabled when there is nothing to remove.
 */
const { tableTotal, isEmpty, clearing, message, error, clearDatabase, reset } = useClearDatabase()

const open = ref(false)
const confirmButton = ref<HTMLButtonElement | null>(null)
const route = useRoute()

function ask() {
  reset()
  open.value = true
}

function close() {
  if (clearing.value) return
  open.value = false
}

async function confirm() {
  await clearDatabase()
  // Leave the dialog up on failure so the reason is readable; the message shown
  // after a success belongs on the page, not behind an overlay.
  if (!error.value) open.value = false
}

// Escape closes, and focus lands on the confirm so the dialog is keyboard-usable.
watch(open, async (isOpen) => {
  if (!isOpen) return
  await nextTick()
  confirmButton.value?.focus()
})

// Navigating away should not leave a destructive dialog hanging over the page.
watch(() => route.fullPath, () => (open.value = false))

const label = computed(() => {
  if (tableTotal.value === null) return 'Removes every imported row. This cannot be undone.'
  if (tableTotal.value === 0) return 'The table is already empty.'
  return `Removes all ${tableTotal.value.toLocaleString()} rows. This cannot be undone.`
})
</script>

<template>
  <div>
    <button
      type="button"
      class="rounded-md border border-red-200 px-3 py-2 text-sm font-medium text-red-700 transition-colors hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:cursor-not-allowed disabled:opacity-40"
      :disabled="isEmpty"
      :title="label"
      @click="ask"
    >
      Clear data
    </button>

    <!-- Confirm dialog -->
    <Teleport to="body">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4"
        @click.self="close"
        @keydown.esc="close"
      >
        <div
          class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="clear-dialog-title"
          aria-describedby="clear-dialog-body"
        >
          <h2 id="clear-dialog-title" class="text-base font-semibold text-slate-900">
            Clear the database?
          </h2>
          <p id="clear-dialog-body" class="mt-1.5 text-sm text-slate-600">
            {{ label }}
          </p>

          <p v-if="error" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
            {{ error }}
          </p>

          <div class="mt-5 flex justify-end gap-2">
            <button
              type="button"
              class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-60"
              :disabled="clearing"
              @click="close"
            >
              Cancel
            </button>
            <button
              ref="confirmButton"
              type="button"
              class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-60"
              :disabled="clearing"
              @click="confirm"
            >
              {{ clearing ? 'Clearing…' : 'Yes, delete everything' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Result, announced wherever the button was pressed from. -->
    <Teleport to="body">
      <p
        v-if="message"
        class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-lg"
        role="status"
      >
        {{ message }}
      </p>
    </Teleport>
  </div>
</template>
