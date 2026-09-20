<?php

namespace App\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Turns a dashboard payload into a downloadable workbook or CSV.
 *
 * ## Formula injection — the reason this class is careful
 *
 * Every value here came out of a spreadsheet somebody uploaded and is going back
 * into one. A cell beginning `=`, `+`, `-`, `@`, tab or carriage return is
 * executed by Excel, LibreOffice and Sheets when the file is opened, and
 * `=cmd|'/c calc'!A1` is a working command execution in Excel's DDE. The
 * database is the right place to keep values verbatim; an export is the wrong
 * place, because here the value stops being data and becomes something a
 * spreadsheet will run.
 *
 * This matters concretely rather than theoretically: the source file behind this
 * project already contains `=L32-K32` in 248 of its cells. Those survive as text
 * through the importer, so without escape() they would land in an export as live
 * formulas.
 *
 * The fix is OWASP's: prefix the offending cell with an apostrophe, which every
 * spreadsheet reads as "this is text". Applied on the way out, not on the way
 * in — the stored value stays faithful.
 */
class ReliabilityExporter
{
    /** Characters that make a spreadsheet treat a cell as a formula. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * The workbook's sections, in order. Each is a sheet in XLSX and a labelled
     * block in CSV.
     *
     * @return list<array{title:string, columns:list<string>, rows:list<list<mixed>>}>
     */
    public function sections(array $payload): array
    {
        $kpis = $payload['kpis'] ?? [];
        $period = $payload['period'] ?? [];
        $applied = $payload['filters']['applied'] ?? [];
        $response = $kpis['response'] ?? [];

        $sections = [];

        // Metric/value rather than one wide row: it reads down the page, and a
        // reader can see the definitions beside the numbers.
        $sections[] = [
            'title' => 'KPIs',
            'columns' => ['Metric', 'Value', 'Basis'],
            'rows' => [
                ['Period', trim(($period['start'] ?? '?') . ' to ' . ($period['end'] ?? '?')), ''],
                ['Days in period', $period['days'] ?? null, ''],
                ['Equipment on roster', $kpis['assets'] ?? null, 'units'],
                ['Units raising work orders', $kpis['assets_reporting'] ?? null, 'units'],
                ['Equipment runtime', $kpis['runtime_hours'] ?? null, 'hours (24/7)'],
                ['Availability', $kpis['availability_pct'] ?? null, '%'],
                ['Serviceability', $kpis['serviceability_pct'] ?? null, '%'],
                ['MTBF', $kpis['mtbf_hours'] ?? null, 'hours'],
                ['MTTR', $kpis['mttr_hours'] ?? null, 'hours'],
                ['Median repair time', $kpis['median_repair_minutes'] ?? null, 'minutes'],
                ['Downtime (asset-down)', $kpis['down_hours'] ?? null, 'hours'],
                ['Repair work (summed)', $kpis['repair_hours'] ?? null, 'hours'],
                ['Work orders', $kpis['work_orders'] ?? null, ''],
                ['Response time (median)', $response['median_minutes'] ?? null, 'minutes'],
                ['Response time (mean)', $response['mean_minutes'] ?? null, 'minutes'],
                ['— rows started before report', $response['rows_started_before_report'] ?? null, 'of ' . ($response['rows_total'] ?? 0)],
                ['Downtime basis', $kpis['basis'] ?? null, ''],
            ],
        ];

        $sections[] = [
            'title' => 'Filters',
            'columns' => ['Filter', 'Value'],
            'rows' => array_map(
                fn ($key) => [$key, $applied[$key] ?? '(all)'],
                array_keys($applied),
            ),
        ];

        $sections[] = [
            'title' => 'Monthly trend',
            'columns' => ['Month', 'Work orders', 'Downtime hours', 'Availability %', 'MTBF hours', 'MTTR minutes'],
            'rows' => array_map(fn ($row) => [
                $row['month'], $row['work_orders'], $row['down_hours'],
                $row['availability_pct'], $row['mtbf_hours'], $row['mttr_minutes'],
            ], $payload['trend'] ?? []),
        ];

        $dimensions = [
            'By fault cause' => 'by_cause',
            'By work type' => 'by_work_type',
            'By fleet' => 'by_asset_type',
            'By location' => 'by_location',
            'Worst equipment' => 'top_assets',
            'Top fault types' => 'top_fault_types',
        ];

        foreach ($dimensions as $title => $key) {
            $sections[] = [
                'title' => $title,
                'columns' => ['Category', 'Work orders', 'Downtime hours'],
                'rows' => array_map(
                    fn ($row) => [$row['label'], $row['work_orders'], $row['repair_hours']],
                    $payload[$key] ?? [],
                ),
            ];
        }

        $sections[] = [
            'title' => 'Repair time spread',
            'columns' => ['Band', 'Work orders'],
            'rows' => array_map(
                fn ($row) => [$row['label'], $row['work_orders']],
                $payload['repair_buckets'] ?? [],
            ),
        ];

        return $sections;
    }

    /** Streams the sections to php://output as one CSV, blocks separated by a blank line. */
    public function streamCsv(array $payload): void
    {
        $out = fopen('php://output', 'w');

        // BOM so Excel opens UTF-8 correctly on a double-click; without it,
        // accented characters arrive mojibaked on Windows.
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($this->sections($payload) as $index => $section) {
            if ($index > 0) {
                fwrite($out, "\n");
            }
            fputcsv($out, [$this->escape($section['title'])]);
            fputcsv($out, array_map($this->escape(...), $section['columns']));
            foreach ($section['rows'] as $row) {
                fputcsv($out, array_map($this->escape(...), $row));
            }
        }

        fclose($out);
    }

    /** Streams the sections to php://output as an XLSX workbook, one sheet each. */
    public function streamXlsx(array $payload, string $filename): void
    {
        $writer = new XlsxWriter();
        $writer->openToBrowser($filename);

        foreach ($this->sections($payload) as $index => $section) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $writer->getCurrentSheet()->setName($this->sheetName($section['title']));

            $writer->addRow(Row::fromValues(array_map($this->escape(...), $section['columns'])));
            foreach ($section['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map($this->escape(...), $row)));
            }
        }

        $writer->close();
    }

    /**
     * Neutralises a value that a spreadsheet would otherwise execute.
     *
     * Numbers and nulls pass through untouched — they cannot carry a formula and
     * escaping them would turn every figure into text, which is worse than
     * useless in a workbook someone wants to chart.
     */
    private function escape(mixed $value): null|bool|float|int|string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $string = (string) $value;

        if ($string === '') {
            return $string;
        }

        // Leading whitespace first: " =1+1" is still a formula to Excel once it
        // trims, so the trigger has to be looked for past any padding.
        return in_array(substr(ltrim($string), 0, 1), self::FORMULA_TRIGGERS, true)
            ? "'" . $string
            : $string;
    }

    /**
     * Excel sheet names cannot exceed 31 characters or contain : \ / ? * [ ].
     * Ours are short and plain, but a future section title might not be.
     */
    private function sheetName(string $title): string
    {
        $clean = preg_replace('/[:\\\\\\/?*\[\]]/', ' ', $title) ?? $title;

        return mb_substr(trim($clean), 0, 31);
    }
}
