<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use League\Csv\Statement;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Shuchkin\SimpleXLS;
use Throwable;

/**
 * Streams a work-order CSV or Excel file into the `add_csv` table.
 *
 * The table has a fixed schema, so there is no type inference here — only
 * casting to the declared column types, with anything uncastable recorded as a
 * row error instead of aborting the import.
 */
class CsvImporter
{
    /** Required headers, in no particular order but all must be present. */
    public const HEADERS = [
        'work_order', 'fault_description', 'asset_number', 'asset_type', 'location', 'workshop',
        'status', 'report_date', 'fault_type', 'work_type', 'act_start', 'act_finish', 'total_downtime', 'fault_cause',
    ];

    /** Columns parsed as datetime. */
    private const DATE_COLUMNS = ['report_date', 'act_start', 'act_finish'];

    /**
     * Columns holding an elapsed time rather than a point in time. Excel stores
     * both as the same kind of number, so only the column tells them apart.
     */
    private const DURATION_COLUMNS = ['total_downtime'];

    /** Excel's day zero. A bare duration or time-of-day is an offset from it. */
    private const EXCEL_EPOCH = '1899-12-30';

    /** Max length per varchar column, so an overlong value is trimmed rather than throwing. */
    private const MAX_LENGTHS = [
        'asset_number' => 100,
        'asset_type' => 255,
        'location' => 100,
        'workshop' => 100,
        'status' => 50,
        'fault_type' => 100,
        'total_downtime' => 50,
    ];

    /**
     * Candidate datetime formats, most-specific first. Day-first is tried
     * before month-first: see detectDateFormat() for how ambiguity is resolved.
     */
    private const DATE_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
        'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
        'd-M-Y H:i:s', 'd-M-Y H:i', 'd-M-Y',
        'd-M-y H:i:s', 'd-M-y H:i', 'd-M-y',
    ];

    private const CHUNK = 500;
    private const MAX_ERRORS = 200;

    /**
     * Ceiling on rows accepted from one file.
     *
     * A 32 MB upload is the size limit, not a row limit: spreadsheet formats
     * compress, so that budget buys millions of rows, an unbounded temporary
     * CSV on disk and a single transaction long enough to hold the table. The
     * real files are ~5,500 rows, so this is generous while still bounded.
     */
    private const MAX_ROWS = 200000;

    /** Caps on the header text quoted back in a mismatch error — see validateHeaders(). */
    private const MAX_HEADERS_ECHOED = 30;
    private const MAX_HEADER_ECHO_LENGTH = 60;

    /** @var array<int, array{row:int, column:?string, message:string, value:?string}> */
    private array $errors = [];

    private int $errorCount = 0;

    /** Tracks temporary files created for transcoding or Excel conversion; deleted after import. */
    private array $tempPaths = [];

    private string $sourceEncoding = 'UTF-8';

    private int $convertedLines = 0;

    /**
     * Strips bytes that are not valid UTF-8.
     *
     * Anything derived from the uploaded file has to pass through this before
     * it can reach a JSON response: json_encode() throws on malformed UTF-8,
     * which would turn a helpful 422 into an unhandled 500 — the exact failure
     * this method exists to prevent.
     */
    public static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * @return array{
     *   rows_read:int, rows_inserted:int, rows_failed:int,
     *   date_formats:array<string,?string>, ambiguous_dates:array<int,string>,
     *   error_count:int, errors:array, duration_ms:int
     * }
     */
    public function import(string $path, bool $truncate = false, ?string $originalName = null): array
    {
        $this->errors = [];
        $this->errorCount = 0;
        $this->tempPaths = [];
        $this->sourceEncoding = 'UTF-8';
        $this->convertedLines = 0;

        try {
            return $this->runImport($path, $truncate, $originalName);
        } finally {
            // All temporary transcoded and converted files are disposable.
            foreach ($this->tempPaths as $tempFile) {
                if (is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
            $this->tempPaths = [];
        }
    }

    /** @return array<string,mixed> */
    private function runImport(string $path, bool $truncate, ?string $originalName = null): array
    {
        $started = microtime(true);

        $csvPath = $this->prepareCsvPath($path, $originalName);
        $reader = $this->openReader($csvPath);

        $rawHeader = $reader->getHeader();

        $headerProblem = $this->validateHeaders($rawHeader);
        if ($headerProblem !== null) {
            throw new \RuntimeException($headerProblem);
        }

        // Read every record under the normalised names, not the raw ones. The
        // check above tolerates stray whitespace, so a header of "work_order "
        // passes validation while castRow()'s $record['work_order'] lookup
        // misses and the column imports as NULL for every row.
        $header = $this->readHeader($rawHeader);

        [$dateFormats, $ambiguous] = $this->detectDateFormats($reader, $header);

        $rowsRead = 0;
        $rowsInserted = 0;
        $buffer = [];
        $now = now();

        DB::beginTransaction();

        try {
            // Clearing the table belongs inside the transaction, and it has to
            // be DELETE rather than TRUNCATE: TRUNCATE is DDL, so MariaDB
            // commits it implicitly and no rollback can undo it. Run outside,
            // it meant any later failure — a malformed row, the row cap, a
            // dropped connection — left the table emptied with nothing put
            // back, from a request that never had to supply a valid file.
            // DELETE leaves AUTO_INCREMENT where it was, which costs nothing
            // here; ids are internal.
            if ($truncate) {
                DB::table('add_csv')->delete();
            }

            // getRecords() is a generator — the file is never fully in memory.
            foreach ($reader->getRecords($header) as $offset => $record) {
                $rowsRead++;

                // Guards the plain-CSV path; the spreadsheet converters apply
                // the same ceiling before they ever reach this loop.
                if ($rowsRead > self::MAX_ROWS) {
                    throw new \RuntimeException(
                        'The file holds more than ' . number_format(self::MAX_ROWS) . ' rows.'
                    );
                }

                // With setHeaderOffset(0) the record offset is already the
                // zero-based file line, so +1 converts it to a 1-based line
                // number that matches what a text editor shows.
                $lineNumber = $offset + 1;

                $row = $this->castRow($record, $lineNumber, $dateFormats);
                if ($row === null) {
                    continue;
                }

                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $buffer[] = $row;

                if (count($buffer) >= self::CHUNK) {
                    DB::table('add_csv')->insert($buffer);
                    $rowsInserted += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DB::table('add_csv')->insert($buffer);
                $rowsInserted += count($buffer);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'source_encoding' => $this->sourceEncoding,
            'converted_lines' => $this->convertedLines,
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_failed' => $rowsRead - $rowsInserted,
            'date_formats' => $dateFormats,
            'ambiguous_dates' => $ambiguous,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Returns a path guaranteed to hold UTF-8, transcoding first if needed.
     *
     * Excel and most Windows tooling export CSV as Windows-1252, not UTF-8.
     * Parsing those bytes as UTF-8 corrupts every accented character and, worse,
     * makes json_encode() throw when any of it reaches a response. Converting up
     * front means the rest of the pipeline only ever sees valid UTF-8.
     */
    private function ensureUtf8(string $path): string
    {
        // Scan EVERY line, not a sample. A file can be pure ASCII for thousands
        // of rows and then hit a Windows-1252 byte — sampling the head declares
        // it UTF-8, conversion is skipped, and the raw byte reaches the INSERT,
        // where strict mode rejects the batch and rolls back the whole import.
        //
        // Reading line by line is safe: a multi-byte UTF-8 sequence never spans
        // a newline, so no character is split across reads.
        $needsConversion = false;
        $in = fopen($path, 'r');
        while (($line = fgets($in)) !== false) {
            if (! mb_check_encoding($line, 'UTF-8')) {
                $needsConversion = true;
                break;
            }
        }
        fclose($in);

        if (! $needsConversion) {
            $this->sourceEncoding = 'UTF-8';

            return $path;
        }

        $this->sourceEncoding = 'Windows-1252';
        $converted = 0;

        $temp = $this->createTempFile('csvutf8_');
        $in = fopen($path, 'r');
        $out = fopen($temp, 'w');

        while (($line = fgets($in)) !== false) {
            // Convert only the offending lines. Files exported from several
            // tools can be mixed, and blanket-converting valid UTF-8 lines from
            // Windows-1252 would mangle characters that were already correct.
            if (! mb_check_encoding($line, 'UTF-8')) {
                $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
                $converted++;
            }
            fwrite($out, $line);
        }

        fclose($in);
        fclose($out);

        $this->convertedLines = $converted;

        return $temp;
    }

    /**
     * Converts XLSX or XLS files to a temporary CSV if needed, or returns the CSV path directly.
     */
    private function prepareCsvPath(string $path, ?string $originalName = null): string
    {
        $fileType = $this->detectFileType($path, $originalName);

        if ($fileType === 'xlsx') {
            return $this->convertXlsxToCsv($path);
        }

        if ($fileType === 'xls') {
            return $this->convertXlsToCsv($path);
        }

        return $path;
    }

    /**
     * Creates a temporary file and registers it for deletion in the same breath.
     *
     * Registering it later — after the conversion loop, say — means any throw in
     * between strands the file: import()'s finally block only cleans up what it
     * has been told about. Those strays hold the full contents of somebody's
     * upload, so they accumulate in a shared temp directory until something else
     * clears it.
     */
    private function createTempFile(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix);
        if ($temp === false) {
            throw new \RuntimeException('Could not create a temporary file for the import.');
        }

        $this->tempPaths[] = $temp;

        return $temp;
    }

    private function detectFileType(string $path, ?string $originalName = null): string
    {
        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        $handle = @fopen($path, 'rb');
        $header = $handle ? fread($handle, 8) : '';
        if ($handle) {
            fclose($handle);
        }

        if (str_starts_with($header, "PK\x03\x04")) {
            return 'xlsx';
        }

        if (str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'xls';
        }

        // The extension is the client's claim; the signature above is the
        // evidence. Routing unsigned bytes into the ZIP or OLE2 parser purely
        // because the name ends in .xlsx is how a parser bug in a third-party
        // library becomes an entry point — and the file would fail to parse
        // anyway, just with a worse error. Say what is actually wrong instead.
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            throw new \RuntimeException(
                "The file is named .{$extension}, but its contents are not a valid Excel workbook."
            );
        }

        return 'csv';
    }

    private function convertXlsxToCsv(string $xlsxPath): string
    {
        $tempCsv = $this->createTempFile('xlsx_conv_');
        $out = fopen($tempCsv, 'w');

        $options = new XlsxOptions();
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new XlsxReader($options);
        $reader->open($xlsxPath);

        // Filled from the header row: the cell indexes that hold durations.
        $durationColumns = [];
        $isHeaderRow = true;
        // Counts the header too, hence the +1 against MAX_ROWS below.
        $rowsWritten = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->getCells();
                    $csvRow = [];
                    $hasContent = false;
                    foreach ($cells as $index => $cell) {
                        // A formula cell's getValue() is the formula text
                        // ("=L32-K32"), which would be stored verbatim. The
                        // cached result is what the spreadsheet actually shows.
                        $val = $cell instanceof FormulaCell
                            ? $cell->getComputedValue()
                            : $cell->getValue();

                        if ($val instanceof DateTimeInterface) {
                            $csvRow[] = isset($durationColumns[$index])
                                ? $this->formatExcelDuration($val)
                                : $val->format('Y-m-d H:i:s');
                            $hasContent = true;
                        } elseif ($val instanceof DateInterval) {
                            $csvRow[] = $this->formatDuration($this->intervalToSeconds($val));
                            $hasContent = true;
                        } elseif (is_bool($val)) {
                            $csvRow[] = $val ? '1' : '0';
                            $hasContent = true;
                        } elseif ($val === null) {
                            $csvRow[] = '';
                        } else {
                            $str = (string) $val;
                            if (trim($str) !== '') {
                                $hasContent = true;
                            }
                            $csvRow[] = $str;
                        }
                    }
                    if (! $hasContent) {
                        continue;
                    }

                    // The first row with content is the header, and it is the
                    // only thing that says which columns hold durations. Blank
                    // leading rows are skipped above, so this cannot latch on
                    // to one of those.
                    if ($isHeaderRow) {
                        foreach ($csvRow as $index => $name) {
                            if (in_array(self::normaliseHeaderName($name), self::DURATION_COLUMNS, true)) {
                                $durationColumns[$index] = true;
                            }
                        }
                        $isHeaderRow = false;
                    }

                    fputcsv($out, $csvRow);

                    if (++$rowsWritten > self::MAX_ROWS + 1) {
                        throw new \RuntimeException(
                            'The spreadsheet holds more than ' . number_format(self::MAX_ROWS) . ' rows.'
                        );
                    }
                }
                break; // Process the first worksheet
            }
        } finally {
            $reader->close();
            fclose($out);
        }

        return $tempCsv;
    }

    /**
     * Renders a duration cell as elapsed time.
     *
     * Excel has no duration type — it counts days from the epoch, so "15
     * minutes" is 0.0104 and "29 days 15 minutes" is 29.0104. The reader turns
     * both back into datetimes (1899-12-30 00:15 and 1900-01-28 00:15), and
     * writing either out as a date would turn downtime into a meaningless
     * timestamp. Hours are not wrapped at 24, so a multi-day downtime survives.
     */
    private function formatExcelDuration(DateTimeInterface $value): string
    {
        // Compare wall-clock to wall-clock. Pre-1900 zones carry odd historical
        // offsets (LMT, not whole hours), so re-reading both sides in UTC is
        // what keeps the subtraction honest.
        $utc = new DateTimeZone('UTC');
        $seconds = (new DateTimeImmutable($value->format('Y-m-d H:i:s'), $utc))->getTimestamp()
            - (new DateTimeImmutable(self::EXCEL_EPOCH . ' 00:00:00', $utc))->getTimestamp();

        // Before the epoch it is not a duration at all; keep it legible rather
        // than emitting a negative time.
        return $seconds >= 0
            ? $this->formatDuration($seconds)
            : $value->format('Y-m-d H:i:s');
    }

    private function intervalToSeconds(DateInterval $interval): int
    {
        $days = $interval->days !== false ? $interval->days : $interval->d;

        return $days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    private function convertXlsToCsv(string $xlsPath): string
    {
        $tempCsv = $this->createTempFile('xls_conv_');
        $out = fopen($tempCsv, 'w');

        try {
            $xls = SimpleXLS::parseFile($xlsPath);
            if ($xls === false) {
                throw new \RuntimeException('Failed to parse Excel (.xls) file: ' . SimpleXLS::parseError());
            }

            $rowsWritten = 0;

            foreach ($xls->rows() as $row) {
                $hasContent = false;
                foreach ($row as $cell) {
                    if (trim((string) $cell) !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if ($hasContent) {
                    fputcsv($out, $row);

                    if (++$rowsWritten > self::MAX_ROWS + 1) {
                        throw new \RuntimeException(
                            'The spreadsheet holds more than ' . number_format(self::MAX_ROWS) . ' rows.'
                        );
                    }
                }
            }
        } finally {
            fclose($out);
        }

        return $tempCsv;
    }

    private function openReader(string $path): Reader
    {
        $path = $this->ensureUtf8($path);
        $reader = Reader::createFromPath($path, 'r');
        $reader->setDelimiter($this->detectDelimiter($path));
        // Strips the UTF-8 BOM, which otherwise corrupts the first header name.
        $reader->skipInputBOM();
        $reader->setHeaderOffset(0);

        return $reader;
    }

    /** Picks whichever candidate delimiter appears most consistently in the first few lines. */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $sample = '';
        for ($i = 0; $i < 5 && ($line = fgets($handle)) !== false; $i++) {
            $sample .= $line;
        }
        fclose($handle);

        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /**
     * Canonical form of one header cell.
     *
     * Spreadsheet exports routinely carry stray padding around header text
     * ("work_order ", " total_downtime", and non-breaking spaces from pasted
     * cells). The header check and the record keys must agree on this form, or
     * a file passes validation and then imports as NULL.
     */
    private static function normaliseHeaderName(mixed $header): string
    {
        // sanitize() first: a header from a mis-encoded file can carry bytes
        // that would later crash json_encode when quoted back in an error.
        $h = (string) self::sanitize((string) $header);
        // Strip a UTF-8 BOM explicitly. trim() works byte-wise, so passing the
        // 3-byte BOM in its character list can shred a multi-byte name.
        $h = preg_replace('/^\x{FEFF}/u', '', $h) ?? $h;
        // \p{Z} catches the non-breaking spaces trim() would leave behind, and
        // /u keeps the trim from splitting a multi-byte name.
        $h = preg_replace('/^[\p{Z}\s"\x27]+|[\p{Z}\s"\x27]+$/u', '', $h) ?? $h;

        return mb_strtolower($h);
    }

    /**
     * The header array records are keyed by: normalised, and padded so every
     * column has a unique non-empty name — League CSV rejects blank or
     * duplicate names, and spreadsheet exports often leave an unnamed trailing
     * column. Placeholder names are simply never read.
     *
     * @param array<int,mixed> $headers
     * @return array<int,string>
     */
    private function readHeader(array $headers): array
    {
        $result = [];
        foreach (array_values($headers) as $index => $header) {
            $name = self::normaliseHeaderName($header);
            if ($name === '' || in_array($name, $result, true)) {
                $name = '_column_' . $index;
            }
            $result[] = $name;
        }

        return $result;
    }

    /** @param array<int,string> $headers */
    public function validateHeaders(array $headers): ?string
    {
        $normalised = array_map(self::normaliseHeaderName(...), $headers);

        // Remove trailing empty headers that spreadsheet tools often include
        while (!empty($normalised) && end($normalised) === '') {
            array_pop($normalised);
        }

        $missing = array_diff(self::HEADERS, $normalised);
        $unexpected = array_diff($normalised, self::HEADERS);

        if ($missing === [] && $unexpected === []) {
            return null;
        }

        $parts = [];
        if ($missing !== []) {
            // Drawn from our own constant, so no bound is needed.
            $parts[] = 'missing: ' . implode(', ', $missing);
        }
        if ($unexpected !== []) {
            $parts[] = 'unexpected: ' . $this->summariseNames($unexpected, ', ');
        }
        // Echo back what was actually read — without this the caller cannot tell
        // a typo from a delimiter or encoding problem.
        $parts[] = 'found ' . count($normalised) . ': ' . $this->summariseNames($normalised, ' | ');

        return 'File headers do not match the expected columns (' . implode('; ', $parts) . ').';
    }

    /**
     * Renders header names read from the file, bounded in both directions.
     *
     * These names are file content on its way into an HTTP response and the
     * log. A file whose delimiter the detector does not recognise parses as a
     * single "header" as long as the whole first line — megabytes, on a 32 MB
     * upload — and every unbounded copy of it is amplification the caller gets
     * for free. Enough survives to diagnose a typo or a delimiter problem.
     *
     * @param array<int,string> $names
     */
    private function summariseNames(array $names, string $glue): string
    {
        $shown = array_map(
            fn (string $name): string => mb_strlen($name) > self::MAX_HEADER_ECHO_LENGTH
                ? mb_substr($name, 0, self::MAX_HEADER_ECHO_LENGTH) . '…'
                : $name,
            array_slice(array_values($names), 0, self::MAX_HEADERS_ECHOED)
        );

        if (count($names) > self::MAX_HEADERS_ECHOED) {
            $shown[] = '… and ' . (count($names) - self::MAX_HEADERS_ECHOED) . ' more';
        }

        return implode($glue, $shown);
    }

    /**
     * Works out the datetime format per date column from a sample of rows.
     *
     * Doing this once per column beats guessing per row, and it is the only way
     * to resolve d/m/Y vs m/d/Y: a value with a first part above 12 proves
     * day-first, above 12 in the second part proves month-first. When the whole
     * sample is ambiguous (every value ≤ 12/12) the column is reported in the
     * `ambiguous` list so the caller can confirm rather than silently guess.
     *
     * @param array<int,string> $header
     * @return array{0: array<string,?string>, 1: array<int,string>}
     */
    private function detectDateFormats(Reader $reader, array $header): array
    {
        $sample = Statement::create()->limit(200)->process($reader, $header);

        $values = array_fill_keys(self::DATE_COLUMNS, []);
        foreach ($sample as $record) {
            foreach (self::DATE_COLUMNS as $column) {
                $value = trim((string) ($record[$column] ?? ''));
                if ($value !== '') {
                    $values[$column][] = $value;
                }
            }
        }

        $formats = [];
        $ambiguous = [];

        foreach ($values as $column => $samples) {
            if ($samples === []) {
                $formats[$column] = null;
                continue;
            }

            $formats[$column] = $this->bestFormat($samples);

            if ($formats[$column] !== null
                && str_contains($formats[$column], '/')
                && $this->isDayMonthAmbiguous($samples)) {
                $ambiguous[] = $column;
            }
        }

        return [$formats, $ambiguous];
    }

    /** @param array<int,string> $samples */
    private function bestFormat(array $samples): ?string
    {
        $best = null;
        $bestHits = 0;

        foreach (self::DATE_FORMATS as $format) {
            $hits = 0;
            foreach ($samples as $value) {
                if ($this->parseWith($value, $format) !== null) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $format;
            }
            // A format matching everything cannot be beaten.
            if ($hits === count($samples)) {
                break;
            }
        }

        // Require most of the sample to agree before trusting a format.
        return $bestHits >= (int) ceil(count($samples) * 0.8) ? $best : null;
    }

    /** True when no sampled value proves whether the first part is day or month. */
    private function isDayMonthAmbiguous(array $samples): bool
    {
        foreach ($samples as $value) {
            if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-]#', $value, $m)) {
                if ((int) $m[1] > 12 || (int) $m[2] > 12) {
                    return false;
                }
            }
        }

        return true;
    }

    private function parseWith(string $value, string $format): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed === false) {
            return null;
        }
        // createFromFormat is lenient: '31/02/2024' rolls into March. Reject that.
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,string|null> $record
     * @param array<string,?string> $dateFormats
     * @return array<string,mixed>|null null when the row cannot be inserted at all
     */
    private function castRow(array $record, int $line, array $dateFormats): ?array
    {
        $get = function (string $key) use ($record): ?string {
            $value = $record[$key] ?? null;
            if ($value === null) {
                return null;
            }
            // Last line of defence. ensureUtf8() should already have transcoded
            // the file, but MariaDB in strict mode aborts the entire batch on a
            // single bad byte, so nothing invalid may reach the INSERT.
            $value = trim((string) self::sanitize((string) $value));

            return $value === '' ? null : $value;
        };

        $row = [];

        // work_order — declared numeric. Non-numeric values cannot be stored.
        $workOrder = $get('work_order');
        if ($workOrder === null) {
            $row['work_order'] = null;
        } elseif (preg_match('/^\d+$/', $workOrder)) {
            $row['work_order'] = (int) $workOrder;
        } else {
            $row['work_order'] = null;
            $this->addError($line, 'work_order', 'Not a whole number; stored as NULL.', $workOrder);
        }

        $row['fault_description'] = $get('fault_description');
        $row['work_type'] = $get('work_type');
        $row['fault_cause'] = $get('fault_cause');

        foreach (self::MAX_LENGTHS as $column => $max) {
            $value = $get($column);
            if ($value !== null && mb_strlen($value) > $max) {
                $this->addError($line, $column, "Longer than {$max} characters; truncated.", $value);
                $value = mb_substr($value, 0, $max);
            }
            $row[$column] = $value;
        }

        $row['total_downtime'] = $this->normaliseDowntime($row['total_downtime'], $line);

        foreach (self::DATE_COLUMNS as $column) {
            $value = $get($column);
            if ($value === null) {
                $row[$column] = null;
                continue;
            }

            $parsed = null;
            $format = $dateFormats[$column] ?? null;
            if ($format !== null) {
                $parsed = $this->parseWith($value, $format);
            }
            // Fall back to trying every format for one-off oddities.
            if ($parsed === null) {
                foreach (self::DATE_FORMATS as $candidate) {
                    $parsed = $this->parseWith($value, $candidate);
                    if ($parsed !== null) {
                        break;
                    }
                }
            }

            // Fall back to Excel numeric date serials (e.g. 45123.5)
            if ($parsed === null && is_numeric($value) && (float) $value > 1000 && (float) $value < 100000) {
                $seconds = (int) round(((float) $value - 25569) * 86400);
                $parsed = gmdate('Y-m-d H:i:s', $seconds);
            }

            if ($parsed === null) {
                $this->addError($line, $column, 'Unrecognised date; stored as NULL.', $value);
            }

            $row[$column] = $parsed;
        }

        return $row;
    }

    /**
     * Reduces `total_downtime` to a number of minutes.
     *
     * It is the dashboard's only measure and the column is VARCHAR, so every
     * aggregate goes through AddCsv::TOTAL_NUMERIC — a value that will not cast
     * to a number contributes nothing. Excel supplies durations as H:i:s (see
     * formatExcelDuration()); a plain number is already minutes and passes
     * through. Anything else is kept verbatim and reported, so a text value
     * shows up in the summary rather than silently counting as zero.
     */
    private function normaliseDowntime(?string $value, int $line): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d+):([0-5]\d)(?::([0-5]\d))?$/', $value, $m) === 1) {
            $minutes = (int) $m[1] * 60 + (int) $m[2] + (int) ($m[3] ?? 0) / 60;

            // Trailing zeros would make an exact 15 read as "15.0000".
            return rtrim(rtrim(number_format($minutes, 4, '.', ''), '0'), '.');
        }

        if (! is_numeric($value)) {
            $this->addError($line, 'total_downtime', 'Not a number or duration; excluded from downtime totals.', $value);
        }

        return $value;
    }

    private function addError(int $line, ?string $column, string $message, ?string $value): void
    {
        $this->errorCount++;
        // Keep only the first N so a badly-formed file cannot balloon the response.
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = [
                'row' => $line,
                'column' => $column,
                'message' => $message,
                // Sanitized: this value comes straight from the file and ends up
                // in a JSON response.
                'value' => $value === null ? null : self::sanitize(mb_substr($value, 0, 120)),
            ];
        }
    }
}
