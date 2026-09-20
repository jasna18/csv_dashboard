<?php

namespace App\Services;

use App\Models\AddCsv;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fleet reliability aggregates over `add_csv`.
 *
 * One class, one round of queries, one payload — every widget on the dashboard
 * draws from a single response, so no two cards can disagree about the slice
 * being shown.
 *
 * ## Operating model
 *
 * The fleet runs 24/7 cargo operations, so equipment runtime is every hour of
 * every day in the window:
 *
 *     runtime_hours = fleet_size × days × 24
 *
 * There are no shift calendars or planned-idle windows to subtract, which makes
 * availability and serviceability comparable across months of different lengths.
 *
 * `fleet_size` is the equipment roster (see EQUIPMENT_FILTERS), **not** the count
 * of units that happen to appear in the filtered rows, and `days` is the selected
 * window, **not** the span of the rows that survived the filter. Getting either
 * wrong silently shrinks the denominator: measuring March against the 28 units
 * that raised a work order — rather than the 90 that were running — reported
 * availability of 76% where it is really 92.6%. A unit with no work orders is a
 * unit that never failed, which is the best case, not an absent one.
 *
 * ## Which downtime column to believe
 *
 * The table carries two answers to "how long was this asset down", and they do
 * not agree:
 *
 *  - **measured** — `act_finish - act_start`. Present and plausible on all 5,563
 *    rows, 503 distinct values, a real distribution.
 *  - **reported** — the `total_downtime` column. Exactly 15 minutes on 95.5% of
 *    rows, because that is what the source spreadsheet holds; only the ~250
 *    cells carrying an `=act_finish-act_start` formula have a genuine figure.
 *
 * So `measured` is the default. `reported` stays available because it is what
 * the source system says and somebody will need to reconcile against it, and
 * `data_quality` quantifies the gap rather than hiding it.
 *
 * ## Availability vs serviceability
 *
 * They answer different questions and are computed differently on purpose:
 *
 *  - **Availability** is time-based — the share of fleet runtime an asset was
 *    not under an open work order, to the minute.
 *  - **Serviceability** is unit-based — the share of the fleet fit for service
 *    on an average day. An asset down for ten minutes counts as unserviceable
 *    for that day, which is how a morning fleet board would read it.
 *
 * Both are built from *merged* work-order intervals. Assets routinely carry
 * several open orders at once (one asset has 1,873 overlapping pairs), so
 * summing durations double-counts wall-clock time and would put availability
 * below zero on a filtered slice.
 *
 * ## Implementation notes
 *
 *  - `total_downtime` is VARCHAR holding MINUTES. Every aggregate over it must
 *    go through AddCsv::TOTAL_NUMERIC; a bare SUM() returns 0 without erroring,
 *    because MariaDB coerces non-numeric text to zero.
 *  - Filter values reach here from the query string. They are bound as
 *    parameters, never interpolated — the only strings spliced into SQL are the
 *    class constants and the whitelisted column name in byDimension().
 */
class ReliabilityReport
{
    public const BASIS_MEASURED = 'measured';
    public const BASIS_REPORTED = 'reported';
    public const BASES = [self::BASIS_MEASURED, self::BASIS_REPORTED];

    /** Filter keys accepted from the request, each matched against its column. */
    public const DIMENSION_FILTERS = ['asset_type', 'location', 'work_type', 'fault_cause', 'asset_number'];

    /**
     * The subset of those filters that changes which *equipment* is in scope.
     *
     * This distinction decides the denominator of every rate on the dashboard.
     * Narrowing to a fleet or a location genuinely removes units from the roster.
     * Narrowing to a fault cause, a work type or a month does not — it selects a
     * subset of work orders, while the same equipment goes on running 24/7. Each
     * asset_number maps to exactly one asset_type and one location in this data
     * (checked: zero assets span either), so these three define a clean roster.
     */
    public const EQUIPMENT_FILTERS = ['asset_type', 'location', 'asset_number'];

    /**
     * Repair minutes from the work-order timestamps.
     *
     * GREATEST guards a finish before its start. Nothing in the current data
     * inverts, but a single negative row would quietly deflate every total.
     */
    private const MEASURED_MINUTES = 'greatest(timestampdiff(minute, act_start, act_finish), 0)';

    /** Repair minutes as the source system reported them. */
    private const REPORTED_MINUTES = AddCsv::TOTAL_NUMERIC;

    /** Minutes from the fault being reported to work starting. See responseTime(). */
    private const RESPONSE_MINUTES = 'timestampdiff(minute, report_date, act_start)';

    /** The value `total_downtime` is padded with on rows the source never filled in. */
    private const PLACEHOLDER_MINUTES = 15;

    /** 24/7 operation: every hour of every day is runtime. */
    private const HOURS_PER_DAY = 24;

    /** Dimensions with too many distinct values to chart whole. */
    private const TOP_N = 10;

    /** @var list<array{label:string, from:float, to:float|null}> */
    private const REPAIR_BUCKETS = [
        ['label' => 'Under 15 min', 'from' => 0, 'to' => 15],
        ['label' => '15–60 min', 'from' => 15, 'to' => 60],
        ['label' => '1–4 hrs', 'from' => 60, 'to' => 240],
        ['label' => '4–24 hrs', 'from' => 240, 'to' => 1440],
        ['label' => 'Over 24 hrs', 'from' => 1440, 'to' => null],
    ];

    /**
     * @param array<string,?string> $filters
     * @return array<string,mixed>
     */
    public function build(array $filters = []): array
    {
        $basis = in_array($filters['basis'] ?? null, self::BASES, true)
            ? $filters['basis']
            : self::BASIS_MEASURED;

        $applied = ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'basis' => $basis];
        foreach (self::DIMENSION_FILTERS as $key) {
            $applied[$key] = $filters[$key] ?? null;
        }

        $period = $this->period($applied);
        $totals = $this->totals($applied, $basis);
        $assets = (int) ($totals->assets ?? 0);

        // One pass over the work-order intervals feeds availability,
        // serviceability and the monthly trend — they are all the same merge.
        $intervals = $this->intervalStats($applied, $period, $assets);

        return [
            'filters' => [
                'applied' => $applied,
                'options' => $this->filterOptions(),
                'data_range' => $this->dataRange(),
            ],
            'period' => $period,
            'kpis' => $this->kpis($totals, $period, $applied, $basis, $intervals),
            'data_quality' => $this->dataQuality($applied),
            'trend' => $this->trend($applied, $basis, $intervals, $this->fleetSize($applied), $period),
            'by_cause' => $this->byDimension($applied, 'fault_cause', $basis),
            'by_work_type' => $this->byDimension($applied, 'work_type', $basis),
            'by_asset_type' => $this->byDimension($applied, 'asset_type', $basis),
            'by_location' => $this->byDimension($applied, 'location', $basis),
            'top_assets' => $this->byDimension($applied, 'asset_number', $basis, self::TOP_N),
            'top_fault_types' => $this->byDimension($applied, 'fault_type', $basis, self::TOP_N),
            'repair_buckets' => $this->repairBuckets($applied, $basis),
        ];
    }

    /** Repair minutes under the chosen basis. Never built from request input. */
    private function minutes(string $basis): string
    {
        return $basis === self::BASIS_REPORTED ? self::REPORTED_MINUTES : self::MEASURED_MINUTES;
    }

    private function hours(string $basis): string
    {
        return '(' . $this->minutes($basis) . ') / 60';
    }

    /**
     * Base query with the filters applied. Every value goes through the
     * builder's bindings.
     *
     * @param array<string,?string> $filters
     */
    private function query(array $filters): Builder
    {
        $query = DB::table('add_csv');

        if (! empty($filters['from'])) {
            $query->whereDate('report_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('report_date', '<=', $filters['to']);
        }
        foreach (self::DIMENSION_FILTERS as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }

    /**
     * The equipment roster the rates are measured against.
     *
     * Deliberately ignores the date window and the work-order filters. A unit
     * that raised no work order in March was not absent from the fleet — it ran
     * all month without failing, which is the best possible outcome and must
     * count toward availability rather than vanish from it. Counting only units
     * that appear in the filtered rows gave March a fleet of 28 instead of 90
     * and reported availability of 76% where it is really above 92%.
     *
     * @param array<string,?string> $filters
     */
    private function fleetSize(array $filters): int
    {
        $query = DB::table('add_csv')->whereNotNull('asset_number');

        foreach (self::EQUIPMENT_FILTERS as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return (int) $query->distinct()->count('asset_number');
    }

    /** @param array<string,?string> $filters */
    private function totals(array $filters, string $basis): object
    {
        return $this->query($filters)
            ->selectRaw('count(*) as work_orders')
            ->selectRaw('count(distinct asset_number) as assets')
            ->selectRaw('sum(' . $this->hours($basis) . ') as repair_hours')
            ->first() ?? (object) [];
    }

    /**
     * The window every rate is measured over, as whole days of 24/7 runtime.
     *
     * @param array<string,?string> $filters
     * @return array{start:?string, end:?string, days:int, hours:float, months:list<string>}
     */
    private function period(array $filters): array
    {
        // The observation window, not the span of the rows that survived the
        // filter. Asking for work type PM previously shrank the period to the
        // 213 days between the first and last PM job, as though the fleet had
        // stopped running either side of them.
        $bounds = $this->dataRange();

        $start = $filters['from'] ?: ($bounds['start'] ?? null);
        $end = $filters['to'] ?: ($bounds['end'] ?? null);

        if ($start === null || $end === null) {
            return ['start' => null, 'end' => null, 'days' => 0, 'hours' => 0.0, 'months' => []];
        }

        $startDay = strtotime(date('Y-m-d', strtotime((string) $start)));
        $endDay = strtotime(date('Y-m-d', strtotime((string) $end)));

        // Inclusive of both end days: a slice covering one calendar day is one
        // day of runtime, not zero.
        $days = (int) max(1, round(($endDay - $startDay) / 86400) + 1);

        $months = [];
        $cursor = strtotime(date('Y-m-01', $startDay));
        while ($cursor <= $endDay) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }

        return [
            'start' => (string) $start,
            'end' => (string) $end,
            'days' => $days,
            'hours' => $days * self::HOURS_PER_DAY,
            'months' => $months,
        ];
    }

    /**
     * Merges every asset's work-order intervals once and derives from them:
     * total down seconds, down seconds per month, and the (asset, day) pairs on
     * which an asset was unserviceable.
     *
     * `cursor()` streams a single ordered query rather than paging with offsets,
     * so the merge sees one stable ordering. At a few thousand rows this costs
     * microseconds; past a few million, push the merge into SQL with window
     * functions — which MariaDB 10.4 has — rather than growing this loop.
     *
     * @param array<string,?string> $filters
     * @param array{days:int, months:list<string>} $period
     * @return array{down_seconds:float, by_month:array<string,float>, unserviceable_asset_days:int}
     */
    private function intervalStats(array $filters, array $period, int $assets): array
    {
        $downSeconds = 0.0;
        $byMonth = array_fill_keys($period['months'] ?? [], 0.0);
        /** @var array<string,bool> $assetDays "asset|Y-m-d" for every day an asset was down */
        $assetDays = [];

        $spans = [];
        $asset = null;
        $openStart = null;
        $openEnd = null;

        $flush = function () use (&$spans, &$asset, &$openStart, &$openEnd): void {
            if ($openStart !== null) {
                $spans[] = [$asset, $openStart, $openEnd];
            }
        };

        $rows = $this->query($filters)
            ->select('asset_number', 'act_start', 'act_finish')
            ->whereNotNull('asset_number')
            ->whereNotNull('act_start')
            ->whereNotNull('act_finish')
            ->orderBy('asset_number')
            ->orderBy('act_start')
            ->orderBy('act_finish')
            ->cursor();

        foreach ($rows as $row) {
            $start = strtotime((string) $row->act_start);
            $end = max($start, strtotime((string) $row->act_finish));

            if ($row->asset_number !== $asset) {
                $flush();
                $asset = $row->asset_number;
                $openStart = $start;
                $openEnd = $end;
                continue;
            }

            if ($start <= $openEnd) {
                $openEnd = max($openEnd, $end);
            } else {
                $spans[] = [$asset, $openStart, $openEnd];
                $openStart = $start;
                $openEnd = $end;
            }
        }
        $flush();

        foreach ($spans as [$spanAsset, $start, $end]) {
            $downSeconds += $end - $start;

            // Attribute the span to the months it actually covers rather than
            // to the month it started in — a 51-day repair belongs to all of
            // them, or the monthly availability line lies about the others.
            foreach ($byMonth as $month => $seconds) {
                $monthStart = strtotime($month . '-01 00:00:00');
                $monthEnd = strtotime('+1 month', $monthStart);
                $overlap = min($end, $monthEnd) - max($start, $monthStart);
                if ($overlap > 0) {
                    $byMonth[$month] += $overlap;
                }
            }

            // Day granularity for serviceability: any overlap marks the day.
            for ($day = strtotime(date('Y-m-d', $start)); $day <= $end; $day += 86400) {
                $assetDays[$spanAsset . '|' . date('Y-m-d', $day)] = true;
            }
        }

        return [
            'down_seconds' => $downSeconds,
            'by_month' => $byMonth,
            'unserviceable_asset_days' => count($assetDays),
        ];
    }

    /**
     * The headline reliability numbers.
     *
     *   Availability   uptime as a share of 24/7 runtime, to the minute.
     *   Serviceability share of the fleet fit for service on an average day.
     *   MTBF           runtime hours per failure.
     *   MTTR           mean repair duration per work order.
     *   Response time  reported → work started. See responseTime() for why this
     *                  one carries a coverage figure.
     *
     * Both rate metrics assume every asset appearing in the slice was in service
     * for the whole period. The table records work orders, not roster changes,
     * so there is nothing to say when an asset joined or left; it makes both
     * figures slightly pessimistic for an asset that arrived mid-period.
     *
     * @param array<string,?string> $filters
     * @param array{down_seconds:float, unserviceable_asset_days:int} $intervals
     * @return array<string,mixed>
     */
    private function kpis(object $totals, array $period, array $filters, string $basis, array $intervals): array
    {
        $workOrders = (int) ($totals->work_orders ?? 0);
        $repairHours = (float) ($totals->repair_hours ?? 0);

        // Units on the roster, and — separately — how many of them actually
        // raised a work order in this slice. The first is the denominator; the
        // second is a finding.
        $fleet = $this->fleetSize($filters);
        $reporting = (int) ($totals->assets ?? 0);

        // Equipment runs 24 hours a day, every day of the window.
        $runtimeHours = $fleet * $period['hours'];
        $downHours = $intervals['down_seconds'] / 3600;
        $uptimeHours = max(0.0, $runtimeHours - $downHours);

        $assetDays = $fleet * $period['days'];
        $serviceableDays = max(0, $assetDays - $intervals['unserviceable_asset_days']);

        return [
            'basis' => $basis,
            'work_orders' => $workOrders,
            'assets' => $fleet,
            'assets_reporting' => $reporting,
            'period_days' => $period['days'],
            'hours_per_day' => self::HOURS_PER_DAY,
            'runtime_hours' => round($runtimeHours, 1),
            'down_hours' => round($downHours, 1),
            'uptime_hours' => round($uptimeHours, 1),
            // Sum of every work order's duration — workload, not unavailability.
            // Higher than down_hours because concurrent orders overlap.
            'repair_hours' => round($repairHours, 1),
            'availability_pct' => $runtimeHours > 0 ? round($uptimeHours / $runtimeHours * 100, 2) : null,
            'serviceability_pct' => $assetDays > 0 ? round($serviceableDays / $assetDays * 100, 2) : null,
            'unserviceable_asset_days' => $intervals['unserviceable_asset_days'],
            'asset_days' => $assetDays,
            'mtbf_hours' => $workOrders > 0 ? round($uptimeHours / $workOrders, 1) : null,
            'mttr_hours' => $workOrders > 0 ? round($repairHours / $workOrders, 2) : null,
            // The mean is pulled right by a few multi-week repairs, so the
            // median ships beside it rather than leaving the tile misleading.
            'median_repair_minutes' => $this->medianRepairMinutes($filters, $basis),
            'response' => $this->responseTime($filters),
        ];
    }

    /**
     * Time from the work order's report date to its actual start.
     *
     *     response_minutes = act_start - report_date
     *
     * Taken from the columns exactly as they stand, across every row — no rows
     * excluded and no sign correction.
     *
     * That means the figure is normally **negative** here: on 5,433 of 5,563
     * rows `act_start` precedes `report_date`, because the work order is entered
     * after the repair has already begun. A negative response time therefore
     * reads as "work started this long before the order was logged", and the
     * sign split below is returned so the dashboard can label it rather than
     * leave a reader guessing why the number is below zero.
     *
     * @param array<string,?string> $filters
     * @return array<string,mixed>
     */
    private function responseTime(array $filters): array
    {
        $row = $this->query($filters)
            ->whereNotNull('report_date')
            ->whereNotNull('act_start')
            ->selectRaw('count(*) as rows_total')
            ->selectRaw('avg(' . self::RESPONSE_MINUTES . ') as mean_minutes')
            ->selectRaw('sum(case when (' . self::RESPONSE_MINUTES . ') < 0 then 1 else 0 end) as rows_before')
            ->selectRaw('sum(case when (' . self::RESPONSE_MINUTES . ') > 0 then 1 else 0 end) as rows_after')
            ->first();

        $total = (int) ($row->rows_total ?? 0);
        $before = (int) ($row->rows_before ?? 0);

        return [
            'rows_total' => $total,
            // Work started before the order was logged — the negative side.
            'rows_started_before_report' => $before,
            'rows_started_after_report' => (int) ($row->rows_after ?? 0),
            'started_before_pct' => $total > 0 ? round($before / $total * 100, 1) : null,
            'mean_minutes' => $total > 0 ? round((float) $row->mean_minutes, 1) : null,
            'median_minutes' => $total > 0 ? $this->medianOf($filters, self::RESPONSE_MINUTES, $total, false) : null,
        ];
    }

    /**
     * Median repair time under the chosen basis.
     *
     * @param array<string,?string> $filters
     */
    private function medianRepairMinutes(array $filters, string $basis): ?float
    {
        $count = $this->query($filters)->count();

        return $count === 0 ? null : $this->medianOf($filters, $this->minutes($basis), $count, false);
    }

    /**
     * Middle value of an expression across the slice.
     *
     * MariaDB 10.4 has no percentile function, so this reads the middle row
     * directly. For an even count it takes the lower of the two middle values
     * rather than averaging, which keeps the figure to a value that actually
     * occurred. `$expression` is always a class constant, never request input.
     *
     * @param array<string,?string> $filters
     */
    private function medianOf(array $filters, string $expression, int $count, bool $positiveOnly = false): ?float
    {
        $query = $this->query($filters);
        if ($positiveOnly) {
            $query->whereRaw($expression . ' >= 0');
        }

        $value = $query
            ->selectRaw($expression . ' as measure')
            ->orderBy('measure')
            ->offset((int) floor(($count - 1) / 2))
            ->limit(1)
            ->value('measure');

        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * Monthly trend: the reliability rates recomputed per calendar month.
     *
     * Availability and serviceability come from the merged intervals clipped to
     * each month, so a repair spanning three months depresses all three rather
     * than only the one it started in.
     *
     * @param array<string,?string> $filters
     * @param array{by_month:array<string,float>} $intervals
     * @return list<array<string,mixed>>
     */
    private function trend(array $filters, string $basis, array $intervals, int $fleet, array $period): array
    {
        $counts = $this->query($filters)
            ->whereNotNull('report_date')
            ->selectRaw("date_format(report_date, '%Y-%m') as month")
            ->selectRaw('count(*) as work_orders')
            ->selectRaw('sum(' . $this->hours($basis) . ') as repair_hours')
            ->selectRaw('avg(' . $this->minutes($basis) . ') as mttr_minutes')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $out = [];

        foreach ($intervals['by_month'] as $month => $downSeconds) {
            $monthStart = strtotime($month . '-01');
            // Days of this month that fall inside the window — a window ending
            // on the 30th does not buy the fleet a 31st day of runtime.
            $daysInMonth = $this->observedDays($month, $period);
            $runtimeHours = $fleet * $daysInMonth * self::HOURS_PER_DAY;
            $downHours = $downSeconds / 3600;
            $uptimeHours = max(0.0, $runtimeHours - $downHours);

            $row = $counts->get($month);
            $workOrders = (int) ($row->work_orders ?? 0);

            $out[] = [
                'month' => $month,
                'work_orders' => $workOrders,
                'down_hours' => round($downHours, 1),
                'repair_hours' => round((float) ($row->repair_hours ?? 0), 1),
                'availability_pct' => $runtimeHours > 0 ? round($uptimeHours / $runtimeHours * 100, 2) : null,
                'mtbf_hours' => $workOrders > 0 ? round($uptimeHours / $workOrders, 1) : null,
                'mttr_minutes' => round((float) ($row->mttr_minutes ?? 0), 1),
            ];
        }

        return $out;
    }

    /**
     * Days of `$month` that fall inside the observation window.
     *
     * @param array{start:?string, end:?string} $period
     */
    private function observedDays(string $month, array $period): int
    {
        $monthStart = strtotime($month . '-01 00:00:00');
        $monthEnd = strtotime('+1 month', $monthStart);

        $windowStart = $period['start'] ? strtotime(date('Y-m-d', strtotime($period['start']))) : $monthStart;
        // Inclusive of the final day, so a window ending mid-month still counts
        // that day as runtime.
        $windowEnd = $period['end']
            ? strtotime(date('Y-m-d', strtotime($period['end']))) + 86400
            : $monthEnd;

        $overlap = min($monthEnd, $windowEnd) - max($monthStart, $windowStart);

        return (int) max(0, round($overlap / 86400));
    }

    /**
     * How far the two downtime columns diverge over the current slice.
     *
     * @param array<string,?string> $filters
     * @return array<string,mixed>
     */
    private function dataQuality(array $filters): array
    {
        $row = $this->query($filters)
            ->selectRaw('count(*) as rows_total')
            ->selectRaw('sum(case when (' . self::REPORTED_MINUTES . ') = ' . self::PLACEHOLDER_MINUTES . ' then 1 else 0 end) as placeholder_rows')
            ->selectRaw('sum(case when (' . self::REPORTED_MINUTES . ') = (' . self::MEASURED_MINUTES . ') then 1 else 0 end) as agreeing_rows')
            ->selectRaw('sum(' . self::REPORTED_MINUTES . ') / 60 as reported_hours')
            ->selectRaw('sum(' . self::MEASURED_MINUTES . ') / 60 as measured_hours')
            ->first();

        $total = (int) ($row->rows_total ?? 0);
        $placeholder = (int) ($row->placeholder_rows ?? 0);

        return [
            'rows_total' => $total,
            'reported_placeholder_rows' => $placeholder,
            'reported_placeholder_pct' => $total > 0 ? round($placeholder / $total * 100, 1) : null,
            'bases_agree_rows' => (int) ($row->agreeing_rows ?? 0),
            'reported_hours' => round((float) ($row->reported_hours ?? 0), 1),
            'measured_hours' => round((float) ($row->measured_hours ?? 0), 1),
            'placeholder_minutes' => self::PLACEHOLDER_MINUTES,
        ];
    }

    /**
     * Work orders and downtime grouped by one dimension, worst downtime first.
     *
     * $limit turns this into a top-N and reports the tail as a single "Other"
     * entry rather than dropping it — `fault_type` has ~850 distinct values, and
     * a chart that silently shows 10 of them misstates the total.
     *
     * @param array<string,?string> $filters
     * @return list<array<string,mixed>>
     */
    private function byDimension(array $filters, string $column, string $basis, ?int $limit = null): array
    {
        // $column is spliced into SQL, so it may only ever be one of these.
        $allowed = [
            'asset_type', 'location', 'fault_cause', 'work_type',
            'asset_number', 'fault_type', 'workshop', 'status',
        ];
        if (! in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Not a groupable column: {$column}");
        }

        $rows = $this->query($filters)
            ->selectRaw("coalesce({$column}, 'Unspecified') as label")
            ->selectRaw('count(*) as work_orders')
            ->selectRaw('sum(' . $this->hours($basis) . ') as repair_hours')
            ->groupBy('label')
            ->orderByDesc('repair_hours')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'work_orders' => (int) $row->work_orders,
                'repair_hours' => round((float) $row->repair_hours, 1),
            ])
            ->all();

        if ($limit === null || count($rows) <= $limit) {
            return $rows;
        }

        $head = array_slice($rows, 0, $limit);
        $tail = array_slice($rows, $limit);

        $head[] = [
            'label' => 'Other (' . count($tail) . ')',
            'work_orders' => array_sum(array_column($tail, 'work_orders')),
            'repair_hours' => round(array_sum(array_column($tail, 'repair_hours')), 1),
            'is_other' => true,
        ];

        return $head;
    }

    /**
     * Repair-time histogram — one pass with a CASE rather than a query per
     * bucket. Bucket bounds come from the class constant, never the request.
     *
     * @param array<string,?string> $filters
     * @return list<array<string,mixed>>
     */
    private function repairBuckets(array $filters, string $basis): array
    {
        $minutes = $this->minutes($basis);
        $cases = [];

        foreach (self::REPAIR_BUCKETS as $index => $bucket) {
            $condition = '(' . $minutes . ') >= ' . (float) $bucket['from'];
            if ($bucket['to'] !== null) {
                $condition .= ' and (' . $minutes . ') < ' . (float) $bucket['to'];
            }
            $cases[] = "sum(case when {$condition} then 1 else 0 end) as bucket_{$index}";
        }

        $row = $this->query($filters)->selectRaw(implode(', ', $cases))->first();

        $out = [];
        foreach (self::REPAIR_BUCKETS as $index => $bucket) {
            $out[] = [
                'label' => $bucket['label'],
                'work_orders' => (int) ($row->{'bucket_' . $index} ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Values the filter controls offer.
     *
     * Drawn from the table and deliberately unfiltered — a control that hides
     * the option you would need to widen your own selection is a dead end.
     *
     * @return array<string, list<string>>
     */
    private function filterOptions(): array
    {
        $distinct = fn (string $column): array => DB::table('add_csv')
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->all();

        return [
            'asset_type' => $distinct('asset_type'),
            'location' => $distinct('location'),
            'work_type' => $distinct('work_type'),
            'fault_cause' => $distinct('fault_cause'),
            'asset_number' => $distinct('asset_number'),
            'month' => DB::table('add_csv')
                ->whereNotNull('report_date')
                ->selectRaw("distinct date_format(report_date, '%Y-%m') as month")
                ->orderBy('month')
                ->pluck('month')
                ->all(),
        ];
    }

    /** @return array{start:?string, end:?string} */
    private function dataRange(): array
    {
        $row = DB::table('add_csv')
            ->selectRaw('min(report_date) as start, max(report_date) as end')
            ->first();

        return ['start' => $row->start ?? null, 'end' => $row->end ?? null];
    }
}
