<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCsvRequest;
use App\Models\AddCsv;
use App\Services\CsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CsvImportController extends Controller
{
    public function __construct(private readonly CsvImporter $importer)
    {
    }

    /**
     * POST /api/csv/import
     *
     * Imports synchronously. At ~5,000 rows this takes well under a second, so
     * queueing it would add a worker dependency and a polling endpoint for no
     * real gain. If the row count grows by an order of magnitude, move the
     * importer call into a queued job and return 202 instead.
     */
    public function store(ImportCsvRequest $request): JsonResponse
    {
        $file = $request->file('file');

        // Keep the uploaded file — it is the only record of what was imported
        // if a row later looks wrong.
        //
        // Name it ourselves. store() derives the extension by sniffing the
        // content, not from the name the validator just checked, so an HTML or
        // SVG payload uploaded as "report.csv" is written as .html or .svg —
        // active content, sitting in a directory any web server pointed at this
        // tree will hand straight back. Only an extension already on the
        // whitelist is allowed to survive the trip to disk.
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ImportCsvRequest::ALLOWED_EXTENSIONS, true)) {
            $extension = 'csv';
        }

        $storedPath = $file->storeAs('datasets', Str::random(40) . '.' . $extension);
        $absolutePath = storage_path('app/private/' . $storedPath);

        if (! is_file($absolutePath)) {
            // Disk layout differs between Laravel versions; fall back to the
            // temp path rather than failing the request.
            $absolutePath = $file->getRealPath();
        }

        try {
            $summary = $this->importer->import(
                $absolutePath,
                $request->boolean('truncate'),
                $file->getClientOriginalName()
            );
        } catch (RuntimeException $e) {
            // Header mismatch — the caller can fix this, so it is a 422.
            // sanitize() is essential here: the message quotes headers read from
            // the file, and json_encode() throws on malformed UTF-8, which would
            // replace this helpful 422 with an unhandled 500.
            return response()->json([
                'message' => CsvImporter::sanitize($e->getMessage()),
                'expected_headers' => CsvImporter::HEADERS,
            ], 422);
        } catch (Throwable $e) {
            // Hand back a handle on the failure, not a description of it. The
            // messages that reach here quote absolute paths, driver internals
            // and — from a QueryException — the entire SQL statement including
            // table and column names. This endpoint is unauthenticated, so none
            // of that may cross the wire; it goes to the log, and the caller
            // gets a reference to quote.
            $reference = (string) Str::uuid();

            Log::error('File import failed', [
                'reference' => $reference,
                // Straight from the client, so it is neither trusted nor
                // allowed to smuggle line breaks into the log.
                'file' => str_replace(["\r", "\n"], ' ', (string) CsvImporter::sanitize($file->getClientOriginalName())),
                'stored_path' => $storedPath,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'The import failed and no rows were saved.',
                'reference' => $reference,
            ], 500);
        }

        return response()->json([
            'message' => "Imported {$summary['rows_inserted']} of {$summary['rows_read']} rows.",
            // The client filename is user-supplied and can also be mis-encoded.
            'file' => CsvImporter::sanitize($file->getClientOriginalName()),
            'stored_path' => $storedPath,
            'summary' => $summary,
            'table_total' => AddCsv::count(),
        ], 201);
    }

    /**
     * DELETE /api/csv/rows — empties `add_csv`.
     *
     * TRUNCATE here, which is the opposite of the choice made inside an import,
     * and for the opposite reason. There, the clear had to roll back with the
     * rows that followed it, so it uses DELETE inside the transaction. Here
     * there is nothing after it that can fail, so there is no rollback to
     * preserve — and TRUNCATE resets AUTO_INCREMENT and returns the storage
     * rather than writing a row-by-row undo log.
     *
     * Destructive and unauthenticated, so it is deliberately awkward to trigger
     * by accident:
     *
     *  - DELETE rather than GET or a form POST. A form POST is CORS-safelisted,
     *    so any page could fire one at this API and the browser would send it;
     *    DELETE forces a preflight, which the CORS config refuses for every
     *    origin but the dashboard's.
     *  - an exact `confirm` value must be present, so a stray or replayed
     *    request does nothing.
     *
     * Neither is a substitute for authentication (TASK.md §3a) — they raise the
     * bar for an accident, not for someone who means it.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', 'in:CLEAR'],
        ]);

        // Counted before the truncate, or there is nothing left to report.
        $removed = AddCsv::count();

        DB::table('add_csv')->truncate();

        // Logged at warning: it is irreversible and leaves no trace in the data
        // itself, so the log is the only record that it happened.
        Log::warning('add_csv cleared', [
            'rows_removed' => $removed,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => $removed === 1
                ? '1 row removed. The table is now empty.'
                : number_format($removed) . ' rows removed. The table is now empty.',
            'rows_removed' => $removed,
            'table_total' => AddCsv::count(),
        ]);
    }

    /** GET /api/csv/summary — quick check of what is currently in the table. */
    public function summary(): JsonResponse
    {
        return response()->json([
            'total_rows' => AddCsv::count(),
            'by_status' => AddCsv::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
            'date_range' => AddCsv::query()
                ->selectRaw('min(report_date) as earliest, max(report_date) as latest')
                ->first(),
            // total_downtime is VARCHAR, so the cast is mandatory — SUM(total_downtime) would
            // return 0 without erroring.
            'total_sum' => AddCsv::query()
                ->selectRaw('sum(' . AddCsv::TOTAL_NUMERIC . ') as value')
                ->value('value'),
        ]);
    }
}
