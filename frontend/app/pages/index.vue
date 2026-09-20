<script setup lang="ts">
useHead({ title: 'Home — CSV Dashboard' })

const config = useRuntimeConfig()

const MAX_BYTES = 32 * 1024 * 1024 // 32 MB — matches the Laravel `max:32768` rule
const ACCEPTED = ['.csv', '.txt', '.xlsx', '.xls']

const fileInput = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const dragging = ref(false)
const error = ref('')
const uploading = ref(false)
const progress = ref(0)
interface ImportError {
  row: number
  column: string | null
  message: string
  value: string | null
}

interface ImportResult {
  message: string
  file: string
  table_total: number
  summary: {
    rows_read: number
    rows_inserted: number
    rows_failed: number
    error_count: number
    errors: ImportError[]
    ambiguous_dates: string[]
    duration_ms: number
  }
}

const result = ref<ImportResult | null>(null)
const expectedHeaders = ref<string[]>([])

/* ------------------------------------------------------- clearing the table */

/*
 * Rows currently stored, so the confirm step can say what is about to go.
 *
 * Read during the server render rather than in onMounted, so the copy names the
 * real number at first paint instead of flickering from "every imported row" to
 * "all 5,563 rows". Copied into a ref because the clear and import handlers
 * write to it; null when the API cannot be reached, and the panel falls back to
 * wording that does not need a number.
 */
const { data: summary } = await useFetch<{ total_rows: number }>(
  () => `${config.public.apiBase}/csv/summary`,
  { key: 'csv-summary', default: () => null },
)

const tableTotal = ref<number | null>(summary.value?.total_rows ?? null)
const clearing = ref(false)
/** Second stage of the confirm. A destructive action should take two decisions. */
const confirmingClear = ref(false)
const clearMessage = ref('')
const clearError = ref('')


async function clearDatabase() {
  clearing.value = true
  clearError.value = ''
  clearMessage.value = ''

  try {
    // DELETE with a JSON body, not a form post: that combination is not
    // CORS-safelisted, so the browser preflights it and only this origin is
    // allowed through. `confirm` is matched exactly server-side.
    const data = await $fetch<{ message: string, table_total: number }>(
      `${config.public.apiBase}/csv/rows`,
      { method: 'DELETE', body: { confirm: 'CLEAR' } },
    )
    clearMessage.value = data.message
    tableTotal.value = data.table_total
    // The import result on screen now describes rows that no longer exist.
    result.value = null
  }
  catch (e: unknown) {
    const status = (e as { statusCode?: number })?.statusCode
    clearError.value = status === 429
      ? 'Too many attempts. Wait a minute and try again.'
      : 'Could not clear the table. Is the API running?'
  }
  finally {
    clearing.value = false
    confirmingClear.value = false
  }
}

const canSubmit = computed(() => !!file.value && !uploading.value && !error.value)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function validate(f: File): string {
  const name = f.name.toLowerCase()
  if (!ACCEPTED.some(ext => name.endsWith(ext))) {
    return 'That file is not supported. Choose a CSV or Excel file (.csv, .xlsx, .xls, .txt).'
  }
  if (f.size === 0) {
    return 'That file is empty.'
  }
  if (f.size > MAX_BYTES) {
    return `That file is ${formatBytes(f.size)}. The limit is 32 MB.`
  }
  return ''
}

function select(f: File | undefined | null) {
  error.value = ''
  result.value = null
  expectedHeaders.value = []
  if (!f) return
  const problem = validate(f)
  if (problem) {
    error.value = problem
    file.value = null
    return
  }
  file.value = f
}

function onPick(e: Event) {
  select((e.target as HTMLInputElement).files?.[0])
}

function onDrop(e: DragEvent) {
  dragging.value = false
  select(e.dataTransfer?.files?.[0])
}

function clear() {
  clearMessage.value = ''
  clearError.value = ''
  file.value = null
  error.value = ''
  progress.value = 0
  result.value = null
  expectedHeaders.value = []
  // Reset the native input too, or picking the same file again fires no change event.
  if (fileInput.value) fileInput.value.value = ''
}

function submit() {
  if (!file.value || uploading.value) return

  uploading.value = true
  progress.value = 0
  error.value = ''

  const body = new FormData()
  body.append('file', file.value)

  // XHR rather than fetch: only XHR reports upload progress.
  const xhr = new XMLHttpRequest()
  xhr.open('POST', `${config.public.apiBase}/csv/import`)
  xhr.setRequestHeader('Accept', 'application/json')

  xhr.upload.addEventListener('progress', (e) => {
    if (e.lengthComputable) progress.value = Math.round((e.loaded / e.total) * 100)
  })

  xhr.addEventListener('load', () => {
    uploading.value = false
    if (xhr.status >= 200 && xhr.status < 300) {
      try {
        result.value = JSON.parse(xhr.responseText) as ImportResult
        // Keep the clear-database panel honest about what it would remove.
        tableTotal.value = result.value.table_total
      } catch {
        error.value = 'Upload succeeded but the server sent a response we could not read.'
      }
    } else if (xhr.status === 422) {
      try {
        const data = JSON.parse(xhr.responseText)
        // Laravel validation failures nest under `errors`; a header mismatch
        // comes back as a plain `message` plus the list we expected.
        const firstValidation = data.errors && typeof data.errors === 'object'
          ? (Object.values(data.errors as Record<string, string[]>)[0] ?? [])[0]
          : null
        error.value = firstValidation ?? data.message ?? 'The server rejected that file.'
        expectedHeaders.value = data.expected_headers ?? []
      } catch {
        error.value = 'The server rejected that file.'
      }
    } else if (xhr.status === 413) {
      error.value = 'The server refused the file as too large. Raise upload_max_filesize and post_max_size in php.ini.'
    } else {
      error.value = `Upload failed (HTTP ${xhr.status}).`
    }
  })

  xhr.addEventListener('error', () => {
    uploading.value = false
    error.value = `Could not reach the API at ${config.public.apiBase}. Is the Laravel backend running?`
  })

  xhr.send(body)
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-12 sm:py-16">
    <div class="text-center">
      <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">
        Turn a CSV or Excel file into a dashboard
      </h1>
      <p class="mt-3 text-slate-600">
        Upload a work-order CSV or Excel file of up to 32 MB. It must carry the 14 expected columns —
        rows with unreadable values are still imported, with those cells left empty.
      </p>
    </div>

    <form class="mt-10" novalidate @submit.prevent="submit">
      <!-- Drop zone / browse -->
      <div
        class="rounded-xl border-2 border-dashed bg-white p-8 text-center transition-colors"
        :class="dragging
          ? 'border-brand-500 bg-brand-50'
          : 'border-slate-300 hover:border-slate-400'"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-.41-8.98 4.5 4.5 0 0 1 8.4-1.98 4.5 4.5 0 0 1 6.32 4.02 4.5 4.5 0 0 1-4.06 4.94" />
        </svg>

        <label for="csv-file" class="mt-4 block text-sm font-medium text-slate-700">
          Drag a CSV or Excel file here, or choose one
        </label>

        <!-- The real input stays in the DOM (not hidden with v-if) so the
             label association and keyboard focus keep working. -->
        <input
          id="csv-file"
          ref="fileInput"
          type="file"
          accept=".csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls"
          class="sr-only"
          :disabled="uploading"
          @change="onPick"
        >

        <button
          type="button"
          class="mt-4 inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-50"
          :disabled="uploading"
          @click="fileInput?.click()"
        >
          Browse file
        </button>

        <p class="mt-3 text-xs text-slate-500">.csv, .xlsx, .xls or .txt · up to 32 MB</p>
      </div>

      <!-- Selected file -->
      <div v-if="file" class="mt-4 flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white px-4 py-3">
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-slate-900">{{ file.name }}</p>
          <p class="text-xs text-slate-500">{{ formatBytes(file.size) }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-md px-2 py-1 text-sm text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
          :disabled="uploading"
          @click="clear"
        >
          Remove
        </button>
      </div>

      <!-- Progress -->
      <div v-if="uploading" class="mt-4">
        <div class="flex justify-between text-xs text-slate-600">
          <span>Uploading…</span>
          <span>{{ progress }}%</span>
        </div>
        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-200">
          <div
            class="h-full rounded-full bg-brand-600 transition-[width] duration-200"
            :style="{ width: `${progress}%` }"
          />
        </div>
      </div>

      <!-- Error -->
      <p
        v-if="error"
        class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
        role="alert"
      >
        {{ error }}
      </p>

      <!-- Expected headers, shown when the server rejected the file's columns -->
      <div v-if="expectedHeaders.length" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-medium">The file must have exactly these {{ expectedHeaders.length }} columns:</p>
        <p class="mt-1.5 font-mono text-xs break-words">{{ expectedHeaders.join(', ') }}</p>
      </div>

      <!-- Success -->
      <div
        v-if="result"
        class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900"
        role="status"
      >
        <p class="font-medium">{{ result.message }}</p>
        <p class="mt-1 text-green-800">
          {{ result.table_total.toLocaleString() }} rows now in the table
          · imported in {{ result.summary.duration_ms }} ms
        </p>

        <!-- Cell-level problems. Rows still imported; the bad values became NULL. -->
        <details v-if="result.summary.error_count" class="mt-2">
          <summary class="cursor-pointer text-amber-800">
            {{ result.summary.error_count }}
            {{ result.summary.error_count === 1 ? 'cell was' : 'cells were' }}
            stored as NULL — click to review
          </summary>
          <ul class="mt-2 space-y-1 text-xs text-slate-700">
            <li v-for="(e, i) in result.summary.errors.slice(0, 20)" :key="i">
              Line {{ e.row }}, <span class="font-mono">{{ e.column }}</span>:
              {{ e.message }}
              <span v-if="e.value" class="font-mono">({{ e.value }})</span>
            </li>
          </ul>
          <p v-if="result.summary.errors.length > 20" class="mt-1 text-xs text-slate-500">
            …and {{ result.summary.error_count - 20 }} more.
          </p>
        </details>

        <!-- Dates that could be read either day-first or month-first. -->
        <p v-if="result.summary.ambiguous_dates.length" class="mt-2 text-amber-800">
          Ambiguous date format in
          <span class="font-mono">{{ result.summary.ambiguous_dates.join(', ') }}</span> —
          every sampled value fits both d/m/Y and m/d/Y. Check these read correctly.
        </p>
      </div>

      <!-- Submit -->
      <button
        type="submit"
        class="mt-6 w-full rounded-lg bg-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40"
        :disabled="!canSubmit"
      >
        {{ uploading ? 'Uploading…' : 'Submit' }}
      </button>
    </form>

    <!-- Clearing the table.
         Set apart from the form and styled as a hazard rather than an action,
         because an import appends and this does not: it empties the table for
         good. The confirm is a second, differently-worded decision rather than a
         browser confirm() — it can name the number of rows at stake, and it
         cannot be dismissed by muscle memory on a dialog that always says the
         same thing. -->
    <section
      class="mt-10 rounded-xl border border-red-200 bg-red-50/60 px-4 py-4"
      aria-labelledby="clear-heading"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 id="clear-heading" class="text-sm font-semibold text-red-900">
            Clear the database
          </h2>
          <p class="mt-0.5 text-xs text-red-800">
            <template v-if="tableTotal === null">
              Removes every imported row. This cannot be undone.
            </template>
            <template v-else-if="tableTotal === 0">
              The table is already empty.
            </template>
            <template v-else>
              Removes all {{ tableTotal.toLocaleString() }} rows. This cannot be undone.
            </template>
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
          <template v-if="!confirmingClear">
            <button
              type="button"
              class="rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 shadow-sm transition hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="uploading || clearing || tableTotal === 0"
              @click="confirmingClear = true"
            >
              Clear database
            </button>
          </template>

          <template v-else>
            <button
              type="button"
              class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-60"
              :disabled="clearing"
              @click="clearDatabase"
            >
              {{ clearing ? 'Clearing…' : 'Yes, delete everything' }}
            </button>
            <button
              type="button"
              class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
              :disabled="clearing"
              @click="confirmingClear = false"
            >
              Cancel
            </button>
          </template>
        </div>
      </div>

      <p v-if="clearMessage" class="mt-3 text-sm font-medium text-red-900" role="status">
        {{ clearMessage }}
      </p>
      <p v-if="clearError" class="mt-3 text-sm text-red-800" role="alert">
        {{ clearError }}
      </p>
    </section>
  </div>
</template>
