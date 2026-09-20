/**
 * The row count, and the one way to empty the table.
 *
 * Shared by the navbar button and the panel on the import page so there is a
 * single confirm value, a single request shape and a single refresh: a second
 * copy of this would be a second thing to get wrong about a destructive action.
 *
 * Both consumers read the same `csv-summary` key, so Nuxt dedupes them into one
 * request and a refresh updates every caller at once.
 */
export function useClearDatabase() {
  const config = useRuntimeConfig()

  const { data: summary } = useFetch<{ total_rows: number }>(
    () => `${config.public.apiBase}/csv/summary`,
    { key: 'csv-summary', default: () => null },
  )

  /** Null when the API cannot be reached — callers fall back to wording that needs no number. */
  const tableTotal = computed<number | null>(() => summary.value?.total_rows ?? null)
  const isEmpty = computed(() => tableTotal.value === 0)

  const clearing = ref(false)
  const message = ref('')
  const error = ref('')

  async function clearDatabase() {
    clearing.value = true
    message.value = ''
    error.value = ''

    try {
      // DELETE with a JSON body, not a form post: that combination is not
      // CORS-safelisted, so the browser preflights it and only this origin is
      // allowed through. `confirm` is matched exactly server-side.
      const data = await $fetch<{ message: string }>(
        `${config.public.apiBase}/csv/rows`,
        { method: 'DELETE', body: { confirm: 'CLEAR' } },
      )

      message.value = data.message

      // Refresh both payloads rather than patching a local count: the button
      // can be pressed from the dashboard, where every widget is now describing
      // rows that no longer exist.
      await refreshNuxtData(['csv-summary', 'reliability-dashboard'])
    }
    catch (e: unknown) {
      const status = (e as { statusCode?: number })?.statusCode
      error.value = status === 429
        ? 'Too many attempts. Wait a minute and try again.'
        : 'Could not clear the table. Is the API running?'
    }
    finally {
      clearing.value = false
    }
  }

  function reset() {
    message.value = ''
    error.value = ''
  }

  return { tableTotal, isEmpty, clearing, message, error, clearDatabase, reset }
}
