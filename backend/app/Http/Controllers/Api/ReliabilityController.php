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
        $validated = $request->validate($this->rules());

        return response()->json($this->report->build($this->filters($validated)));
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
        $validated = $request->validate(
            $this->rules() + ['format' => ['nullable', 'string', 'in:csv,xlsx']],
        );

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
     * Validation for both the dashboard read and the export.
     *
     * Every dimension accepts a list as well as a scalar, so the multi-select UI
     * can send `asset_type[]=STC&asset_type[]=ETV` while an existing caller
     * passing `asset_type=STC` keeps working. `array` on the parent plus a rule
     * on `.*` is what makes Laravel validate each element rather than the list.
     *
     * The values themselves are length-capped only. They are matched against the
     * column, so an unknown one yields an empty dashboard rather than an error —
     * the honest answer to "show me asset type ZZZ".
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $dimensions = [
            'asset_type' => 255,
            'location' => 100,
            'work_type' => 255,
            'fault_cause' => 255,
            'asset_number' => 100,
        ];

        $rules = [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'month' => ['nullable'],
            'month.*' => ['string', 'regex:/^\d{4}-\d{2}$/'],
            'basis' => ['nullable', 'string', 'in:' . implode(',', ReliabilityReport::BASES)],
        ];

        foreach ($dimensions as $key => $max) {
            $rules[$key] = ['nullable'];
            $rules[$key . '.*'] = ['string', 'max:' . $max];
        }

        return $rules;
    }

    /**
     * Normalises the validated query into the filter array the report expects.
     *
     * A scalar becomes a one-element list so everything downstream sees one
     * shape, and `month` stays a set rather than being flattened into from/to —
     * a range cannot express "January and March but not February".
     *
     * @param array<string,mixed> $validated
     * @return array<string,mixed>
     */
    private function filters(array $validated): array
    {
        $filters = [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'basis' => $validated['basis'] ?? null,
            'month' => $this->listOf($validated['month'] ?? null),
        ];

        foreach (ReliabilityReport::DIMENSION_FILTERS as $key) {
            $filters[$key] = $this->listOf($validated[$key] ?? null);
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    private function listOf(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => (string) $item, (array) $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
