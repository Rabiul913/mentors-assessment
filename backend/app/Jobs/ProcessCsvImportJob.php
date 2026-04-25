<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\Sale;
use App\Services\CsvCleanerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;

/**
 * ProcessCsvImportJob
 *
 * Reads the uploaded CSV in streaming mode (never loads all rows at once),
 * cleans each row via CsvCleanerService, and bulk-inserts in chunks of 500.
 *
 * MEMORY SAFETY:
 * - League\Csv\Reader streams records lazily.
 * - We collect at most CHUNK_SIZE rows before flushing to DB.
 * - insertOrIgnore() on raw_row_hash unique index handles duplicates at DB level.
 *
 * DUPLICATE DETECTION:
 * - raw_row_hash (SHA-256 of original row) has a UNIQUE index.
 * - insertOrIgnore() silently skips hash collisions.
 * - We compare inserted count vs attempted to tally skipped_duplicates.
 */
class ProcessCsvImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly string $jobId,
        private readonly string $storedPath,
    ) {}

    public function handle(CsvCleanerService $cleaner): void
    {
        $importJob = ImportJob::where('job_id', $this->jobId)->firstOrFail();
        $importJob->update(['status' => 'processing']);

        // Error log CSV writer (in-memory until flushed to disk)
        $errorRows   = [];
        $errorHeader = ['row_number', 'sale_id', 'errors'];

        $totalRows        = 0;
        $inserted         = 0;
        $skippedDuplicates = 0;
        $skippedInvalid   = 0;

        try {
            $absolutePath = Storage::disk('local')->path($this->storedPath);
            $csv = Reader::createFromPath($absolutePath, 'r');
            $csv->setHeaderOffset(0);   // first row is header

            $chunk = [];

            foreach ($csv->getRecords() as $offset => $record) {
                $rowNumber = $offset + 2; // +2: 1-indexed + header row
                $totalRows++;

                $result = $cleaner->clean($record);

                if (!empty($result['errors'])) {
                    $skippedInvalid++;
                    $errorRows[] = [
                        $rowNumber,
                        $record['sale_id'] ?? '',
                        implode('; ', $result['errors']),
                    ];
                    continue;
                }

                $chunk[] = array_merge($result['clean'], [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (count($chunk) >= self::CHUNK_SIZE) {
                    [$ins, $dup] = $this->flushChunk($chunk);
                    $inserted          += $ins;
                    $skippedDuplicates += $dup;
                    $chunk = [];
                }
            }

            // Flush remaining rows
            if (!empty($chunk)) {
                [$ins, $dup] = $this->flushChunk($chunk);
                $inserted          += $ins;
                $skippedDuplicates += $dup;
            }

            // Write error log CSV if there were any invalid rows
            $errorLogPath = null;
            if (!empty($errorRows)) {
                $errorLogPath = $this->writeErrorLog($errorRows, $errorHeader);
            }

            $importJob->update([
                'status'            => 'done',
                'total_rows'        => $totalRows,
                'inserted'          => $inserted,
                'skipped_duplicates'=> $skippedDuplicates,
                'skipped_invalid'   => $skippedInvalid,
                'error_log_path'    => $errorLogPath,
            ]);

        } catch (\Throwable $e) {
            Log::error("ImportJob {$this->jobId} failed: " . $e->getMessage());
            $importJob->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            // Clean up the uploaded temp file
            Storage::disk('local')->delete($this->storedPath);
        }
    }

    /**
     * Bulk-insert a chunk and return [inserted_count, duplicate_count].
     * Uses insertOrIgnore so the unique hash constraint handles duplicates gracefully.
     */
    private function flushChunk(array $chunk): array
    {
        $attempted = count($chunk);

        // insertOrIgnore returns number of rows actually inserted
        $insertedCount = DB::table('sales')->insertOrIgnore($chunk);

        $duplicates = $attempted - $insertedCount;

        return [$insertedCount, $duplicates];
    }

    private function writeErrorLog(array $rows, array $header): string
    {
        $path = 'error_logs/' . $this->jobId . '_errors.csv';
        $csv  = Writer::createFromString();
        $csv->insertOne($header);
        $csv->insertAll($rows);

        Storage::disk('local')->put($path, $csv->toString());
        return $path;
    }
}
