<?php

use App\Http\Controllers\Api\CsvImportController;
use App\Http\Controllers\Api\ReliabilityController;
use Illuminate\Support\Facades\Route;

/*
 * These endpoints are unauthenticated (auth is a v1 non-goal — see TASK.md §3),
 * so throttling is the only thing standing between a stranger and an unbounded
 * run of 32 MB uploads. Import is the expensive, destructive one: it parses the
 * whole file, writes every row, and with `truncate` empties the table first.
 *
 * This is a mitigation, not a fix. Anyone who can reach the API can still
 * replace the dataset; only authentication closes that.
 */
Route::middleware('throttle:10,1')->post('/csv/import', [CsvImportController::class, 'store']);
Route::middleware('throttle:60,1')->get('/csv/summary', [CsvImportController::class, 'summary']);

/*
 * Empties the table. Throttled hardest of the three: it is the only irreversible
 * operation here, and nothing legitimate needs to call it in a loop.
 */
Route::middleware('throttle:5,1')->delete('/csv/rows', [CsvImportController::class, 'destroyAll']);

/*
 * Read-only aggregates behind the dashboard. Same reasoning as above: no auth
 * yet, so the throttle is what stops a stranger replaying an expensive
 * group-by. It is a touch more generous than the import limit because the
 * dashboard refetches on every filter change.
 */
Route::middleware('throttle:120,1')->get('/reliability/dashboard', [ReliabilityController::class, 'dashboard']);
