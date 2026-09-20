/** One grouped row — every dimension widget shares this shape. */
export interface DimensionRow {
  label: string
  work_orders: number
  repair_hours: number
  /** Set on the folded tail of a top-N, so the chart can de-emphasise it. */
  is_other?: boolean
}

export interface TrendRow {
  month: string
  work_orders: number
  down_hours: number
  repair_hours: number
  availability_pct: number | null
  mtbf_hours: number | null
  mttr_minutes: number
}

/**
 * Response time straight from the columns: `act_start - report_date`, every row,
 * no exclusions. Normally negative in this data — work starts before the order
 * is logged — so the sign split travels with the figure.
 */
export interface ResponseStats {
  rows_total: number
  rows_started_before_report: number
  rows_started_after_report: number
  started_before_pct: number | null
  mean_minutes: number | null
  median_minutes: number | null
}

export interface ReliabilityPayload {
  filters: {
    applied: Record<string, string | string[] | null>
    options: {
      asset_type: string[]
      location: string[]
      work_type: string[]
      fault_cause: string[]
      asset_number: string[]
      month: string[]
    }
    data_range: { start: string | null, end: string | null }
  }
  period: { start: string | null, end: string | null, days: number, hours: number, months: string[] }
  kpis: {
    basis: 'measured' | 'reported'
    work_orders: number
    /** Equipment on the roster — the denominator of every rate. */
    assets: number
    /** How many of those actually raised a work order in this slice. */
    assets_reporting: number
    period_days: number
    hours_per_day: number
    runtime_hours: number
    down_hours: number
    uptime_hours: number
    repair_hours: number
    availability_pct: number | null
    serviceability_pct: number | null
    unserviceable_asset_days: number
    asset_days: number
    mtbf_hours: number | null
    mttr_hours: number | null
    median_repair_minutes: number | null
    response: ResponseStats
  }
  data_quality: {
    rows_total: number
    reported_placeholder_rows: number
    reported_placeholder_pct: number | null
    bases_agree_rows: number
    reported_hours: number
    measured_hours: number
    placeholder_minutes: number
  }
  trend: TrendRow[]
  by_cause: DimensionRow[]
  by_work_type: DimensionRow[]
  by_asset_type: DimensionRow[]
  by_location: DimensionRow[]
  top_assets: DimensionRow[]
  top_fault_types: DimensionRow[]
  repair_buckets: { label: string, work_orders: number }[]
}

/** The filter dimensions, each a set. Empty means "no constraint", not "none". */
export const FILTER_DIMENSIONS = [
  'month',
  'asset_type',
  'asset_number',
  'location',
  'work_type',
  'fault_cause',
] as const

export type FilterDimension = typeof FILTER_DIMENSIONS[number]

export type ReliabilityFilters =
  & Record<FilterDimension, string[]>
  & { basis: 'measured' | 'reported' }

/**
 * Fetches the whole dashboard as one payload.
 *
 * One request for a dozen widgets is the point: every card is guaranteed to be
 * showing the same slice, which is the failure mode you get when each chart
 * fetches for itself and one of them lags a filter change behind.
 *
 * `useFetch` keeps the previous `data` in place while the next one lands, so the
 * grid holds its layout instead of collapsing into skeletons on every change.
 */
export function useReliability() {
  const config = useRuntimeConfig()

  const filters = reactive<ReliabilityFilters>({
    month: [],
    asset_type: [],
    asset_number: [],
    location: [],
    work_type: [],
    fault_cause: [],
    basis: 'measured',
  })

  /**
   * The query string, built by hand rather than handed to the fetch layer as an
   * object.
   *
   * Laravel reads a repeated `asset_type=A&asset_type=B` as the single value B;
   * only the `[]` suffix makes it an array. Serialising here keeps that detail
   * in one place and out of every caller.
   *
   * An empty set contributes nothing, so "all" is expressed by absence — the API
   * then has one meaning for "no constraint" instead of two.
   */
  const queryString = computed(() => {
    const params = new URLSearchParams()
    params.set('basis', filters.basis)

    for (const key of FILTER_DIMENSIONS) {
      for (const value of filters[key]) {
        params.append(`${key}[]`, value)
      }
    }

    return params.toString()
  })

  const { data, pending, error, refresh } = useFetch<ReliabilityPayload>(
    () => `${config.public.apiBase}/reliability/dashboard?${queryString.value}`,
    { watch: [queryString], key: 'reliability-dashboard' },
  )

  function reset() {
    for (const key of FILTER_DIMENSIONS) {
      filters[key] = []
    }
  }

  /** Dimensions currently constrained — what "Clear 3" counts. */
  const activeCount = computed(
    () => FILTER_DIMENSIONS.filter(key => filters[key].length > 0).length,
  )

  // `queryString` is returned so the export button can request exactly the slice
  // on screen rather than rebuilding the filter logic and drifting from it.
  return { filters, queryString, data, pending, error, refresh, reset, activeCount }
}
