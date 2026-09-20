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
    applied: Record<string, string | null>
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

export interface ReliabilityFilters {
  month: string | null
  asset_type: string | null
  asset_number: string | null
  location: string | null
  work_type: string | null
  fault_cause: string | null
  basis: 'measured' | 'reported'
}

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
    month: null,
    asset_type: null,
    asset_number: null,
    location: null,
    work_type: null,
    fault_cause: null,
    basis: 'measured',
  })

  const query = computed(() => {
    // Empty values are dropped rather than sent as `month=`, which the API
    // would have to special-case as "no filter" on top of the null it handles.
    const out: Record<string, string> = { basis: filters.basis }
    for (const key of ['month', 'asset_type', 'asset_number', 'location', 'work_type', 'fault_cause'] as const) {
      const value = filters[key]
      if (value) out[key] = value
    }
    return out
  })

  const { data, pending, error, refresh } = useFetch<ReliabilityPayload>(
    () => `${config.public.apiBase}/reliability/dashboard`,
    { query, watch: [query], key: 'reliability-dashboard' },
  )

  function reset() {
    filters.month = null
    filters.asset_type = null
    filters.asset_number = null
    filters.location = null
    filters.work_type = null
    filters.fault_cause = null
  }

  const activeCount = computed(() =>
    [filters.month, filters.asset_type, filters.asset_number, filters.location, filters.work_type, filters.fault_cause]
      .filter(Boolean).length,
  )

  // `query` is returned so the export button can request exactly the slice
  // on screen rather than rebuilding the filter logic and drifting from it.
  return { filters, query, data, pending, error, refresh, reset, activeCount }
}
