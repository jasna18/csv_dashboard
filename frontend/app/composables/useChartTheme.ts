import type { ApexOptions } from 'apexcharts'

/**
 * The one place chart colour and chrome are decided.
 *
 * Every chart on the dashboard merges `baseOptions` and takes its colours from
 * here, so "make the charts consistent" is a change to this file rather than a
 * sweep through nine components. It is also the swap point if the project ever
 * moves to ECharts (frontend/TASK.md §2) — the palette survives, the options
 * object is what gets rewritten.
 */

/**
 * Categorical hues, in fixed order.
 *
 * Assign by slot index and never cycle: a series keeps its colour when a filter
 * removes its neighbours, so a reader who learned "Operational is blue" is not
 * misled by the next render. Blue and green lead because the dashboard is
 * blue-and-green; the tail exists so a 4th and 5th category are still
 * distinguishable rather than pretty.
 *
 * Validated as a set (light mode, white surface): lightness band, chroma floor,
 * colour-vision separation (worst adjacent pair ΔE 9.1 protan, gate is ≥8) and
 * normal-vision separation (worst ΔE 22.9, floor is 15) all pass. Two slots sit
 * under 3:1 against white, which is why every chart using them also ships direct
 * labels and a table view — that is the documented relief, not an oversight.
 *
 * Past five categories, fold the tail into "Other" (the API already does this
 * for the top-N dimensions). Never generate a sixth hue.
 */
export const SERIES = [
  '#2a78d6', // 1 blue
  '#1baf7a', // 2 green
  '#eda100', // 3 yellow
  '#4a3aa7', // 4 violet
  '#e34948', // 5 red
] as const

/**
 * Ordered single-hue ramp, light → dark, for buckets that have a natural order
 * (repair-time bands). Validated as an ordinal ramp: monotone lightness, every
 * adjacent step ≥0.06 apart, light end still clearing the surface at 2.11:1.
 *
 * Not to be used on nominal categories — colouring bars darker-where-bigger
 * double-encodes the bar length and wastes the only free channel.
 */
export const ORDINAL_BLUE = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#0d366b'] as const

/** Reserved for state. Never a series colour, always paired with a label. */
export const STATUS = {
  good: '#0ca30c',
  warning: '#fab219',
  serious: '#ec835a',
  critical: '#d03b3b',
} as const

/** Chart chrome — mirrors the custom properties in assets/css/main.css. */
export const CHROME = {
  surface: '#ffffff',
  grid: '#e8e8e3',
  axis: '#c3c2b7',
  ink: '#0b0b0b',
  inkMuted: '#6b6a66',
  deemphasis: '#d6d5cf',
} as const

const FONT = 'system-ui, -apple-system, "Segoe UI", sans-serif'

/**
 * Availability read as a state.
 *
 * The thresholds are a placeholder for a real service-level target — there is
 * nothing in the source data that states one. Change them here, and the gauge,
 * its label and its colour all move together.
 */
export function availabilityStatus(pct: number | null): {
  key: keyof typeof STATUS
  label: string
  color: string
} {
  if (pct === null) return { key: 'warning', label: 'No data', color: CHROME.inkMuted }
  if (pct >= 95) return { key: 'good', label: 'On target', color: STATUS.good }
  if (pct >= 85) return { key: 'warning', label: 'Below target', color: STATUS.warning }
  if (pct >= 70) return { key: 'serious', label: 'Well below target', color: STATUS.serious }
  return { key: 'critical', label: 'Critical', color: STATUS.critical }
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/**
 * Number formatting.
 *
 * Locales are pinned to en-US and month names come from the array above rather
 * than `toLocaleString`. Both render on the server and again on the client: let
 * either pick up the ambient locale and the two disagree, which Vue reports as a
 * hydration mismatch. `compact` keeps axis ticks from wrapping at four digits.
 */
export const fmt = {
  int: (n: number | null | undefined) =>
    n === null || n === undefined ? '—' : Math.round(n).toLocaleString('en-US'),
  hours: (n: number | null | undefined) =>
    n === null || n === undefined ? '—' : `${Math.round(n).toLocaleString('en-US')} hrs`,
  compact: (n: number | null | undefined) =>
    n === null || n === undefined
      ? '—'
      : Math.abs(n) >= 1000
        ? `${(n / 1000).toFixed(n >= 10000 ? 0 : 1)}k`
        : String(Math.round(n)),
  pct: (n: number | null | undefined, dp = 1) =>
    n === null || n === undefined ? '—' : `${n.toFixed(dp)}%`,
  /**
   * Minutes as the largest sensible unit — 10 min, 3.5 hrs, 2.1 days.
   *
   * The unit is chosen from the magnitude and the sign is put back afterwards,
   * so −1,220 reads as “−20.3 hrs” rather than “−1220 min”. Response time is
   * routinely negative in this data (work starts before the order is logged),
   * and a sign that silently changes the unit would hide that.
   */
  duration: (minutes: number | null | undefined) => {
    if (minutes === null || minutes === undefined) return '—'
    const sign = minutes < 0 ? '\u2212' : ''
    const size = Math.abs(minutes)
    if (size < 60) return `${sign}${Math.round(size)} min`
    if (size < 1440) return `${sign}${(size / 60).toFixed(1)} hrs`
    return `${sign}${(size / 1440).toFixed(1)} days`
  },
  /** 2026-03 → Mar 2026. */
  month: (iso: string) => {
    const [year, month] = iso.split('-')
    return `${MONTHS[Number(month) - 1] ?? month} ${year}`
  },
  /** 2026-01-01 06:11:00 → 1 Jan 2026. */
  date: (value: string | null) => {
    if (!value) return '—'
    const [date] = value.split(' ')
    const [year, month, day] = (date ?? '').split('-')
    if (!year || !month || !day) return value
    return `${Number(day)} ${MONTHS[Number(month) - 1] ?? month} ${year}`
  },
}

/**
 * Defaults every chart inherits.
 *
 * Deliberate choices worth not undoing:
 *  - no toolbar: the export menu is chrome nobody asked for, and it overlaps titles
 *  - solid hairline gridlines, horizontal only: dashes read as "threshold"
 *  - `dataLabels` off by default: a number on every mark is chaos; charts that
 *    want labels turn them on selectively
 *  - animations short — a dashboard that refetches on filter change should not
 *    replay a 0.8s ease on nine cards at once
 */
export const baseOptions: ApexOptions = {
  chart: {
    fontFamily: FONT,
    toolbar: { show: false },
    zoom: { enabled: false },
    animations: { enabled: true, speed: 220 },
    background: 'transparent',
    parentHeightOffset: 0,
  },
  dataLabels: { enabled: false },
  grid: {
    borderColor: CHROME.grid,
    strokeDashArray: 0,
    xaxis: { lines: { show: false } },
    yaxis: { lines: { show: true } },
    padding: { top: 0, right: 8, bottom: 0, left: 8 },
  },
  states: {
    hover: { filter: { type: 'lighten' } },
    active: { filter: { type: 'none' } },
  },
  tooltip: {
    style: { fontSize: '12px', fontFamily: FONT },
    marker: { show: true },
  },
  legend: {
    fontFamily: FONT,
    fontSize: '12px',
    labels: { colors: CHROME.inkMuted },
    markers: { size: 6 },
    itemMargin: { horizontal: 8, vertical: 2 },
  },
  xaxis: {
    axisBorder: { color: CHROME.axis },
    axisTicks: { color: CHROME.axis },
    labels: {
      style: { colors: CHROME.inkMuted, fontSize: '11px', fontFamily: FONT },
    },
  },
  yaxis: {
    labels: {
      style: { colors: CHROME.inkMuted, fontSize: '11px', fontFamily: FONT },
    },
  },
}

/** Deep-merges chart options over the shared base. Arrays replace, not concat. */
export function withBase(options: ApexOptions): ApexOptions {
  return merge(baseOptions, options)
}

function merge<T extends Record<string, any>>(base: T, over: Record<string, any>): T {
  const out: Record<string, any> = Array.isArray(base) ? [...(base as any)] : { ...base }

  for (const [key, value] of Object.entries(over)) {
    const existing = out[key]
    out[key] =
      value && typeof value === 'object' && !Array.isArray(value)
        && existing && typeof existing === 'object' && !Array.isArray(existing)
        ? merge(existing, value)
        : value
  }

  return out as T
}
