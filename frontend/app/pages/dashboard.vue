<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

/**
 * Fleet reliability dashboard — 24/7 cargo operation.
 *
 * Layout follows the supplied reference (header strip, KPI row, grid of small
 * cards on a light ground) with two deliberate departures:
 *
 *  - no sidebar, as asked, so the header carries the title and the basis toggle;
 *  - filters are one row scoping the whole grid rather than a dropdown per card.
 *    Per-card filters put several different slices on screen at once with no way
 *    to tell which card is showing what.
 *
 * Every widget draws from a single API payload, so no two cards can disagree
 * about the slice being shown.
 */
useHead({ title: 'Fleet Reliability' })

const { filters, query, data, pending, error, reset, activeCount } = useReliability()

const kpis = computed(() => data.value?.kpis)
const quality = computed(() => data.value?.data_quality)
const trend = computed(() => data.value?.trend ?? [])
const options = computed(() => data.value?.filters.options)

const availabilityState = computed(() => availabilityStatus(kpis.value?.availability_pct ?? null))
const serviceabilityState = computed(() => availabilityStatus(kpis.value?.serviceability_pct ?? null))

const periodLabel = computed(() => {
  const period = data.value?.period
  if (!period?.start || !period?.end) return 'No data'
  return `${fmt.date(period.start)} – ${fmt.date(period.end)} · ${fmt.int(period.days)} days`
})

const basisNote = computed(() =>
  filters.basis === 'measured'
    ? 'Repair time measured from work-order timestamps (act_start → act_finish).'
    : 'Repair time as the source system reported it in total_downtime.',
)

/*
 * Fixed chart heights rather than one derived per category count. Cards in a
 * grid row stretch to the tallest of them, so sizing each chart to its own data
 * leaves the shorter cards with a band of dead space underneath.
 */
const GRID_CHART_HEIGHT = 290
const WIDE_CHART_HEIGHT = 330

/** The service-level line the gauges and the availability trend are read against. */
const AVAILABILITY_TARGET = 95

const months = computed(() => trend.value.map(row => fmt.month(row.month)))

/**
 * Bar colours for a grouped chart.
 *
 * One hue for the real categories — a colour ramp keyed to bar length would
 * double-encode what the bar already shows. The folded "Other" row is the one
 * exception: it is not a peer of the rows above it, so it recedes to grey.
 */
function barColors(rows: DimensionRow[], hue: string) {
  return rows.map(row => (row.is_other ? CHROME.deemphasis : hue))
}

/** Shared shape for the horizontal "by dimension" charts. */
function horizontalBars(rows: DimensionRow[], hue: string, key: 'repair_hours' | 'work_orders') {
  const format = key === 'repair_hours' ? fmt.hours : fmt.int

  return {
    series: [{ name: key === 'repair_hours' ? 'Downtime' : 'Work orders', data: rows.map(row => row[key]) }],
    options: {
      chart: { type: 'bar' },
      colors: barColors(rows, hue),
      plotOptions: {
        bar: {
          horizontal: true,
          // Lets the "Other" row differ; with it on, Apex wants a legend entry
          // per bar, which is noise for what is really one series.
          distributed: true,
          borderRadius: 4,
          borderRadiusApplication: 'end',
          barHeight: '62%',
        },
      },
      legend: { show: false },
      grid: { xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
      xaxis: {
        categories: rows.map(row => row.label),
        labels: { formatter: (value: string) => fmt.compact(Number(value)) },
      },
      yaxis: { labels: { maxWidth: 140 } },
      tooltip: { y: { formatter: (value: number) => format(value) } },
    } as ApexOptions,
  }
}

/** Shared shape for the monthly trend columns. */
function trendColumns(values: number[], hue: string, format: (value: number) => string) {
  return {
    series: [{ name: 'Value', data: values }],
    options: {
      chart: { type: 'bar' },
      colors: [hue],
      plotOptions: { bar: { borderRadius: 4, borderRadiusApplication: 'end', columnWidth: '58%' } },
      legend: { show: false },
      xaxis: { categories: months.value },
      yaxis: { labels: { formatter: (value: number) => fmt.compact(value) } },
      tooltip: { y: { formatter: format } },
    } as ApexOptions,
  }
}

/** Pie for a part-to-whole split. Direct labels plus a legend, never colour alone. */
function pie(rows: DimensionRow[], key: 'repair_hours' | 'work_orders') {
  const format = key === 'repair_hours' ? fmt.hours : fmt.int

  return {
    series: rows.map(row => row[key]),
    options: {
      chart: { type: 'donut' },
      labels: rows.map(row => row.label),
      colors: [...SERIES].slice(0, Math.max(rows.length, 1)),
      // A 2px surface-coloured gap between segments, not a border around them.
      stroke: { width: 2, colors: [CHROME.surface] },
      // Some of these hues sit under 3:1 on white; the visible percentage is the
      // documented relief, and the legend keeps identity off colour alone. Only
      // slices with room get a label — a cramped one would be clipped.
      dataLabels: {
        enabled: true,
        formatter: (value: number) => (value >= 7 ? `${Math.round(value)}%` : ''),
        style: { fontSize: '11px', fontWeight: 600 },
        dropShadow: { enabled: false },
      },
      legend: { position: 'bottom', horizontalAlign: 'center' },
      plotOptions: {
        pie: {
          donut: {
            size: '58%',
            labels: {
              show: true,
              value: { fontSize: '20px', fontWeight: 600, color: CHROME.ink },
              total: {
                show: true,
                label: key === 'repair_hours' ? 'Total downtime' : 'Work orders',
                color: CHROME.inkMuted,
                fontSize: '11px',
                formatter: () => format(rows.reduce((sum, row) => sum + row[key], 0)),
              },
            },
          },
        },
      },
      tooltip: { y: { formatter: format } },
    } as ApexOptions,
  }
}

function gauge(pct: number | null | undefined, state: { label: string, color: string }) {
  return {
    series: [pct === null || pct === undefined ? 0 : Number(pct.toFixed(1))],
    options: {
      chart: { type: 'radialBar' },
      colors: [state.color],
      labels: [state.label],
      stroke: { lineCap: 'round' },
      plotOptions: {
        radialBar: {
          startAngle: -135,
          endAngle: 135,
          hollow: { size: '62%' },
          track: { background: '#eef1f5', strokeWidth: '100%' },
          dataLabels: {
            name: { offsetY: 24, color: CHROME.inkMuted, fontSize: '12px' },
            value: {
              offsetY: -12,
              fontSize: '30px',
              fontWeight: 600,
              color: CHROME.ink,
              formatter: (value: number) => `${value}%`,
            },
          },
        },
      },
    } as ApexOptions,
  }
}

/* ---------------------------------------------------------------- widgets */

const availabilityGauge = computed(() => gauge(kpis.value?.availability_pct, availabilityState.value))
const serviceabilityGauge = computed(() => gauge(kpis.value?.serviceability_pct, serviceabilityState.value))

/*
 * The same five causes, once by count and once by hours. Side by side they
 * carry the finding the dashboard exists for: the cause that raises the fewest
 * work orders accounts for most of the downtime.
 */
const causeByOrders = computed(() => pie(data.value?.by_cause ?? [], 'work_orders'))
const causeByDowntime = computed(() => pie(data.value?.by_cause ?? [], 'repair_hours'))

/** Lower bound for the availability axis — see the yaxis note below. */
const availabilityFloor = computed(() => {
  const values = trend.value.map(row => row.availability_pct ?? 0)
  if (!values.length) return 0
  const lowest = Math.min(...values, AVAILABILITY_TARGET)
  return Math.max(0, Math.floor((lowest - 5) / 10) * 10)
})

/** Availability against target. One measure, one axis — never a second scale. */
const availabilityTrend = computed(() => ({
  series: [{ name: 'Availability', data: trend.value.map(row => row.availability_pct ?? 0) }],
  options: {
    chart: { type: 'line' },
    colors: [SERIES[0]],
    stroke: { width: 2, curve: 'straight' },
    markers: { size: 4, strokeWidth: 2, strokeColors: CHROME.surface },
    legend: { show: false },
    xaxis: { categories: months.value },
    yaxis: {
      // Floored just below the worst month rather than pinned to 0: availability
      // lives at the top of the range, and a 0–100 axis renders every month as
      // the same flat line. The axis stays labelled in %, so the truncation is
      // visible rather than implied, and the floor never rises above the target
      // so the target line cannot fall off the chart.
      min: availabilityFloor.value,
      max: 100,
      tickAmount: 5,
      labels: { formatter: (value: number) => `${Math.round(value)}%` },
    },
    annotations: {
      yaxis: [{
        y: AVAILABILITY_TARGET,
        borderColor: STATUS.good,
        strokeDashArray: 0,
        label: {
          text: `Target ${AVAILABILITY_TARGET}%`,
          position: 'left',
          textAnchor: 'start',
          style: { background: STATUS.good, color: '#ffffff', fontSize: '10px' },
        },
      }],
    },
    tooltip: { y: { formatter: (value: number) => fmt.pct(value, 2) } },
  } as ApexOptions,
}))

const downtimeTrend = computed(() =>
  trendColumns(trend.value.map(row => row.down_hours), SERIES[0], fmt.hours))

const mttrTrend = computed(() =>
  trendColumns(trend.value.map(row => row.mttr_minutes), SERIES[1], fmt.duration))

const mtbfTrend = computed(() => ({
  series: [{ name: 'MTBF', data: trend.value.map(row => row.mtbf_hours ?? 0) }],
  options: {
    chart: { type: 'line' },
    colors: [SERIES[1]],
    stroke: { width: 2, curve: 'straight' },
    markers: { size: 4, strokeWidth: 2, strokeColors: CHROME.surface },
    legend: { show: false },
    xaxis: { categories: months.value },
    yaxis: { labels: { formatter: (value: number) => fmt.compact(value) } },
    tooltip: { y: { formatter: (value: number) => fmt.hours(value) } },
  } as ApexOptions,
}))

const repairSpread = computed(() => {
  const rows = data.value?.repair_buckets ?? []
  return {
    series: [{ name: 'Work orders', data: rows.map(row => row.work_orders) }],
    options: {
      chart: { type: 'bar' },
      // Ordered bands, so an ordered single-hue ramp is right here — the one
      // chart on the page whose categories have a natural sequence.
      colors: [...ORDINAL_BLUE].slice(0, rows.length),
      plotOptions: { bar: { distributed: true, borderRadius: 4, borderRadiusApplication: 'end', columnWidth: '58%' } },
      legend: { show: false },
      xaxis: { categories: rows.map(row => row.label) },
      yaxis: { labels: { formatter: (value: number) => fmt.compact(value) } },
      tooltip: { y: { formatter: (value: number) => fmt.int(value) } },
    } as ApexOptions,
  }
})

const byFleet = computed(() => horizontalBars(data.value?.by_asset_type ?? [], SERIES[0], 'repair_hours'))
const byLocation = computed(() => horizontalBars(data.value?.by_location ?? [], SERIES[0], 'repair_hours'))
const byWorkType = computed(() => horizontalBars(data.value?.by_work_type ?? [], SERIES[1], 'work_orders'))
const topAssets = computed(() => horizontalBars(data.value?.top_assets ?? [], SERIES[0], 'repair_hours'))
const topFaults = computed(() => horizontalBars(data.value?.top_fault_types ?? [], SERIES[1], 'work_orders'))

/* ------------------------------------------------------------ table twins */

const dimensionColumns = [
  { key: 'label', label: 'Category' },
  { key: 'work_orders', label: 'Work orders', numeric: true },
  { key: 'repair_hours', label: 'Downtime', numeric: true },
]

function dimensionRows(rows: DimensionRow[]) {
  return rows.map(row => ({
    label: row.label,
    work_orders: fmt.int(row.work_orders),
    repair_hours: fmt.hours(row.repair_hours),
  }))
}

const trendColumnsSpec = [
  { key: 'month', label: 'Month' },
  { key: 'availability', label: 'Availability', numeric: true },
  { key: 'work_orders', label: 'Work orders', numeric: true },
  { key: 'down_hours', label: 'Downtime', numeric: true },
  { key: 'mtbf', label: 'MTBF', numeric: true },
  { key: 'mttr', label: 'MTTR', numeric: true },
]

const trendRows = computed(() =>
  trend.value.map(row => ({
    month: fmt.month(row.month),
    availability: fmt.pct(row.availability_pct, 2),
    work_orders: fmt.int(row.work_orders),
    down_hours: fmt.hours(row.down_hours),
    mtbf: row.mtbf_hours === null ? '—' : fmt.hours(row.mtbf_hours),
    mttr: fmt.duration(row.mttr_minutes),
  })),
)

const bucketRows = computed(() =>
  (data.value?.repair_buckets ?? []).map(row => ({
    label: row.label,
    work_orders: fmt.int(row.work_orders),
  })),
)

/** Every dimension select shares one shape. */
const selects = computed(() => [
  { key: 'month' as const, label: 'Month', values: options.value?.month ?? [], all: 'All months' },
  { key: 'asset_type' as const, label: 'Fleet', values: options.value?.asset_type ?? [], all: 'All fleets' },
  { key: 'asset_number' as const, label: 'Equipment', values: options.value?.asset_number ?? [], all: 'All equipment' },
  { key: 'location' as const, label: 'Location', values: options.value?.location ?? [], all: 'All locations' },
  { key: 'work_type' as const, label: 'Work type', values: options.value?.work_type ?? [], all: 'All work types' },
  { key: 'fault_cause' as const, label: 'Fault cause', values: options.value?.fault_cause ?? [], all: 'All causes' },
])
</script>

<template>
  <div class="mx-auto max-w-[1600px] px-4 py-5 sm:px-6 lg:px-8">
    <!-- Header -->
    <div class="rounded-xl bg-gradient-to-r from-brand-800 to-brand-600 px-5 py-4 text-white shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-lg font-semibold tracking-tight sm:text-xl">
            dnata Cargo FG05 - Reliability & Performance Dashboard 
          </h1>
          <p class="mt-0.5 text-sm text-brand-100">
            {{ periodLabel }}
            <span v-if="kpis">
              · {{ fmt.int(kpis.assets) }} units × {{ kpis.hours_per_day }} h/day
              = {{ fmt.hours(kpis.runtime_hours) }} runtime
            </span>
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <DashboardExportButton :query="query" :disabled="!data" />

          <!-- Which downtime column to believe. Named on the surface because the
               two disagree by thousands of hours. -->
          <div class="flex rounded-lg bg-white/15 p-0.5" role="group" aria-label="Downtime basis">
          <button
            v-for="option in (['measured', 'reported'] as const)"
            :key="option"
            type="button"
            class="rounded-md px-3 py-1.5 text-xs font-medium capitalize transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :class="filters.basis === option ? 'bg-white text-brand-700' : 'text-white/90 hover:bg-white/10'"
            :aria-pressed="filters.basis === option"
            @click="filters.basis = option"
          >
              {{ option }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter row: one control set, scoping every card below it. -->
    <div class="mt-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <div v-for="select in selects" :key="select.key" class="flex min-w-0 flex-col gap-1">
        <label :for="`filter-${select.key}`" class="text-xs font-medium text-slate-500">
          {{ select.label }}
        </label>
        <select
          :id="`filter-${select.key}`"
          v-model="filters[select.key]"
          class="max-w-[11rem] rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-700 focus:border-brand-500 focus:outline-none"
        >
          <option :value="null">{{ select.all }}</option>
          <option v-for="value in select.values" :key="value" :value="value">
            {{ select.key === 'month' ? fmt.month(value) : value }}
          </option>
        </select>
      </div>

      <button
        v-if="activeCount"
        type="button"
        class="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        @click="reset"
      >
        Clear {{ activeCount }}
      </button>

      <p class="w-full text-xs text-slate-500 sm:ml-auto sm:w-auto sm:max-w-sm" aria-live="polite">
        <span v-if="pending">Updating…</span>
        <span v-else>{{ basisNote }}</span>
      </p>
    </div>

    <div v-if="error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      Could not load the dashboard. Is the API running at
      <code class="font-mono text-xs">{{ useRuntimeConfig().public.apiBase }}</code>?
    </div>

    <!-- Source caveats. The headline figures rest on columns with known
         problems, and saying so is cheaper than being trusted wrongly. -->
    <div v-if="quality && kpis" class="mt-4 grid gap-2 md:grid-cols-2">
      <div
        v-if="quality.reported_placeholder_pct && quality.reported_placeholder_pct > 20"
        class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3"
      >
        <span class="shrink-0 text-base leading-none text-amber-600" aria-hidden="true">&#9888;</span>
        <p class="text-xs leading-relaxed text-amber-900">
          <strong class="font-semibold">Downtime source.</strong>
          {{ fmt.int(quality.reported_placeholder_rows) }} of {{ fmt.int(quality.rows_total) }} rows
          ({{ fmt.pct(quality.reported_placeholder_pct) }}) hold exactly
          {{ quality.placeholder_minutes }} minutes in <code class="font-mono">total_downtime</code> —
          a placeholder in the source, not a measurement. Only
          {{ fmt.int(quality.bases_agree_rows) }} agree with their own timestamps, so
          <strong>measured</strong> is the default.
        </p>
      </div>

      <div
        v-if="kpis.response.started_before_pct !== null && kpis.response.started_before_pct > 0"
        class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
      >
        <span class="shrink-0 text-base leading-none text-slate-500" aria-hidden="true">&#9432;</span>
        <p class="text-xs leading-relaxed text-slate-700">
          <strong class="font-semibold">Reading response time.</strong>
          It is <code class="font-mono">act_start &minus; report_date</code> taken from the columns
          as they stand, across all {{ fmt.int(kpis.response.rows_total) }} rows. On
          {{ fmt.int(kpis.response.rows_started_before_report) }} of them
          ({{ fmt.pct(kpis.response.started_before_pct) }}) work starts <em>before</em> the report
          date, so the figure is negative — read it as how long work had been under way by the time
          the order was logged.
        </p>
      </div>
    </div>

    <!-- KPI row -->
    <div v-if="kpis" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
      <DashboardStatTile
        label="Serviceability"
        :value="fmt.pct(kpis.serviceability_pct, 1)"
        :status="serviceabilityState"
        :sub="`${fmt.int(kpis.asset_days - kpis.unserviceable_asset_days)} of ${fmt.int(kpis.asset_days)} unit-days fit`"
        accent="green"
        definition="Share of the fleet fit for service on an average day. An asset with any open work order that day counts as unserviceable."
      />
      <DashboardStatTile
        label="Availability"
        :value="fmt.pct(kpis.availability_pct, 1)"
        :status="availabilityState"
        :sub="`${fmt.hours(kpis.uptime_hours)} up of ${fmt.hours(kpis.runtime_hours)}`"
        definition="Share of equipment runtime the fleet was not under an open work order, to the minute. Overlapping repairs on one asset count once."
      />
      <DashboardStatTile
        label="Equipment runtime"
        :value="fmt.hours(kpis.runtime_hours)"
        :sub="`${fmt.int(kpis.assets)} units × ${fmt.int(kpis.period_days)} days × ${kpis.hours_per_day} h`"
        definition="24/7 cargo operation, so every hour of every day counts as runtime. Measured against the whole equipment roster — a unit that raised no work order was running, not absent."
      />
      <DashboardStatTile
        label="MTBF"
        :value="kpis.mtbf_hours === null ? '—' : `${fmt.int(kpis.mtbf_hours)} hrs`"
        sub="operating hours per failure"
        accent="green"
        definition="Mean time between failures: uptime hours divided by work orders raised."
      />
      <DashboardStatTile
        label="MTTR"
        :value="kpis.mttr_hours === null ? '—' : `${kpis.mttr_hours.toFixed(1)} hrs`"
        :sub="`median ${fmt.duration(kpis.median_repair_minutes)}`"
        definition="Mean time to repair. The median sits beside it because a few multi-week jobs pull the mean far to the right."
      />
      <DashboardStatTile
        label="Downtime"
        :value="fmt.hours(kpis.down_hours)"
        :sub="`${fmt.hours(kpis.repair_hours)} of repair work · ${fmt.int(kpis.assets_reporting)}/${fmt.int(kpis.assets)} units affected`"
        definition="Wall-clock hours at least one work order was open on an asset, counted once per asset. Repair work sums every order's duration, which is higher because orders overlap."
      />
      <DashboardStatTile
        label="Response time"
        :value="fmt.duration(kpis.response.median_minutes)"
        :sub="kpis.response.median_minutes === null
          ? 'no rows'
          : `median · mean ${fmt.duration(kpis.response.mean_minutes)}`"
        accent="green"
        definition="act_start minus report_date, taken from the columns as they stand across every row. Negative means work started before the order was logged."
      />
    </div>

    <!-- Widget grid -->
    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
      <DashboardWidgetCard title="Availability" :hint="availabilityState.label" :loading="pending" :tabular="false">
        <ChartsBaseChart type="radialBar" :series="availabilityGauge.series" :options="availabilityGauge.options" :height="GRID_CHART_HEIGHT" />
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Serviceability" :hint="serviceabilityState.label" :loading="pending" :tabular="false">
        <ChartsBaseChart type="radialBar" :series="serviceabilityGauge.series" :options="serviceabilityGauge.options" :height="GRID_CHART_HEIGHT" />
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Faults by cause" hint="Share of work orders raised" :loading="pending">
        <ChartsBaseChart type="donut" :series="causeByOrders.series" :options="causeByOrders.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.by_cause ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Downtime by cause" hint="Same causes, weighted by hours lost" :loading="pending">
        <ChartsBaseChart type="donut" :series="causeByDowntime.series" :options="causeByDowntime.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.by_cause ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Availability trend" :hint="`Against a ${AVAILABILITY_TARGET}% target`" :span="2" :loading="pending">
        <ChartsBaseChart type="line" :series="availabilityTrend.series" :options="availabilityTrend.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="trendColumnsSpec" :rows="trendRows" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Downtime by month" hint="Asset hours lost" :span="2" :loading="pending">
        <ChartsBaseChart type="bar" :series="downtimeTrend.series" :options="downtimeTrend.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="trendColumnsSpec" :rows="trendRows" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="MTBF trend" hint="Operating hours per failure, by month" :span="2" :loading="pending">
        <ChartsBaseChart type="line" :series="mtbfTrend.series" :options="mtbfTrend.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="trendColumnsSpec" :rows="trendRows" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="MTTR trend" hint="Mean repair time in minutes, by month" :span="2" :loading="pending">
        <ChartsBaseChart type="bar" :series="mttrTrend.series" :options="mttrTrend.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="trendColumnsSpec" :rows="trendRows" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Repair time spread" hint="Work orders by how long they stayed open" :loading="pending">
        <ChartsBaseChart type="bar" :series="repairSpread.series" :options="repairSpread.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable
            :columns="[{ key: 'label', label: 'Band' }, { key: 'work_orders', label: 'Work orders', numeric: true }]"
            :rows="bucketRows"
          />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Downtime by fleet" hint="Which equipment classes cost the most hours" :loading="pending">
        <ChartsBaseChart type="bar" :series="byFleet.series" :options="byFleet.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.by_asset_type ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Work order mix" hint="By work type" :loading="pending">
        <ChartsBaseChart type="bar" :series="byWorkType.series" :options="byWorkType.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.by_work_type ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Downtime by location" hint="Where the hours are lost" :loading="pending">
        <ChartsBaseChart type="bar" :series="byLocation.series" :options="byLocation.options" :height="GRID_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.by_location ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Worst equipment by downtime" hint="Top 10, remainder folded into Other" :span="2" :loading="pending">
        <ChartsBaseChart type="bar" :series="topAssets.series" :options="topAssets.options" :height="WIDE_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.top_assets ?? [])" />
        </template>
      </DashboardWidgetCard>

      <DashboardWidgetCard title="Most frequent faults" hint="Top 10 fault types by work orders" :span="2" :loading="pending">
        <ChartsBaseChart type="bar" :series="topFaults.series" :options="topFaults.options" :height="WIDE_CHART_HEIGHT" />
        <template #table>
          <DashboardDataTable :columns="dimensionColumns" :rows="dimensionRows(data?.top_fault_types ?? [])" />
        </template>
      </DashboardWidgetCard>
    </div>
  </div>
</template>
