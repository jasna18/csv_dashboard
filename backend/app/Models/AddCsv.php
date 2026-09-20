<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One work-order row imported from the source CSV.
 *
 * Note the mixed-type columns (asset_number, location, workshop, fault_type, total_downtime) are
 * strings by design — they hold both numbers and text, so any numeric cast here
 * would silently discard the text values.
 */
class AddCsv extends Model
{
    protected $table = 'add_csv';

    protected $fillable = [
        'work_order',
        'fault_description',
        'asset_number',
        'asset_type',
        'location',
        'workshop',
        'status',
        'report_date',
        'fault_type',
        'work_type',
        'act_start',
        'act_finish',
        'total_downtime',
        'fault_cause',
    ];

    protected function casts(): array
    {
        return [
            'work_order' => 'integer',
            'report_date' => 'datetime',
            'act_start' => 'datetime',
            'act_finish' => 'datetime',
        ];
    }

    /**
     * `total_downtime` holds a number of MINUTES. Excel supplies it as a duration
     * cell, which CsvImporter converts on the way in; the column stays VARCHAR
     * because the source also mixes in free text.
     *
     * MariaDB coerces non-numeric text to 0 in SUM() without raising an error,
     * so every aggregate over it must cast explicitly. Use this expression
     * rather than writing SUM(total_downtime) anywhere.
     */
    public const TOTAL_NUMERIC = "CAST(NULLIF(TRIM(total_downtime), '') AS DECIMAL(18,4))";
}
