<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReliabilityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReliabilityController extends Controller
{
    public function __construct(private readonly ReliabilityReport $report)
    {
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
}
