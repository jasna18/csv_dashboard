<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReliabilityExporter;
use App\Services\ReliabilityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReliabilityController extends Controller
{
    public function __construct(
        private readonly ReliabilityReport $report,
        private readonly ReliabilityExporter $exporter,
    ) {
    }

    /**
     * GET /api/reliability/dashboard
     *
     * The whole dashboard in one response. Every widget is served from the same
     * query pass, so no card can be showing a different slice than its
     * neighbour — the failure mode you get when each chart fetches for itself.
     *
     * Filters are validated here and bound as parameters downstream. `date`
     * rather than `date_format` on purpose: the frontend sends Y-m-d, but a
     * human hitting the endpoint by hand should not be tripped by that.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // A convenience over from/to: "2026-03" expands to that whole month.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            // Length caps only. The values are matched against the column, so an
            // unknown one yields an empty dashboard rather than an error — which
            // is the honest answer to "show me asset type ZZZ".
            'asset_type' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:100'],
            'work_type' => ['nullable', 'string', 'max:255'],
            'fault_cause' => ['nullable', 'string', 'max:255'],
            'asset_number' => ['nullable', 'string', 'max:100'],
            // Which downtime column to believe. See ReliabilityReport's class
            // docblock — the two disagree, and the difference is the point.
            'basis' => ['nullable', 'string', 'in:' . implode(',', ReliabilityReport::BASES)],
        ]);

        $filters = [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'basis' => $validated['basis'] ?? null,
        ];

        foreach (ReliabilityReport::DIMENSION_FILTERS as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }

        // An explicit month wins over a loose from/to, so the two controls
        // cannot quietly contradict each other.
        if (! empty($validated['month'])) {
            $start = $validated['month'] . '-01';
            $filters['from'] = $start;
            $filters['to'] = date('Y-m-t', strtotime($start));
        }

        return response()->json($this->report->build($filters));
    }

    /**
     * GET /api/reliability/export
     *
     * The same dashboard, as a workbook. Every widget becomes a sheet in XLSX or
     * a labelled block in CSV, over whatever slice the filters describe — so an
     * export always matches what was on screen when it was asked for.
     *
     * Streamed rather than built in memory: the payload is small today, but a
     * StreamedResponse costs nothing extra and means a larger fleet does not
     * turn this into a memory limit.
     *
     * Every cell goes through ReliabilityExporter::escape(). This endpoint is
     * the formula-injection sink the security review predicted — values from an
     * uploaded spreadsheet on their way back into one — and the source file for
     * this project already carries `=L32-K32` in 248 cells.
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'asset_type' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:100'],
            'work_type' => ['nullable', 'string', 'max:255'],
            'fault_cause' => ['nullable', 'string', 'max:255'],
            'asset_number' => ['nullable', 'string', 'max:100'],
            'basis' => ['nullable', 'string', 'in:' . implode(',', ReliabilityReport::BASES)],
        ]);

        $format = $validated['format'] ?? 'xlsx';
        $payload = $this->report->build($this->filters($validated));

        $filename = 'fleet-reliability-' . now()->format('Y-m-d') . '.' . $format;

        return new StreamedResponse(
            function () use ($format, $payload, $filename): void {
                $format === 'csv'
                    ? $this->exporter->streamCsv($payload)
                    : $this->exporter->streamXlsx($payload, $filename);
            },
            200,
            $format === 'csv'
                ? [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    // The filename is ours, not the caller's, so there is nothing
                    // here for a header-injection or path-traversal attempt to use.
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]
                // OpenSpout's openToBrowser() sets its own Content-Type and
                // Content-Disposition, so setting them here would duplicate them.
                : [],
        );
    }

    /**
     * Normalises the validated query into the filter array the report expects.
     *
     * @param array<string,mixed> $validated
     * @return array<string,?string>
     */
    private function filters(array $validated): array
    {
        $filters = [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'basis' => $validated['basis'] ?? null,
        ];

        foreach (ReliabilityReport::DIMENSION_FILTERS as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }

        if (! empty($validated['month'])) {
            $start = $validated['month'] . '-01';
            $filters['from'] = $start;
            $filters['to'] = date('Y-m-t', strtotime($start));
        }

        return $filters;
    }
}
