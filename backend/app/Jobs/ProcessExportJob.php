<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $jobId) {}

    public function handle(): void
    {
        $job = ExportJob::where('job_id', $this->jobId)->firstOrFail();
        $job->update(['status' => 'processing']);

        $filters = $job->filters ?? [];

        try {
            $filePath = $job->format === 'csv'
                ? $this->generateCsv($filters)
                : $this->generateExcel($filters);

            $job->update(['status' => 'done', 'file_path' => $filePath]);
        } catch (\Throwable $e) {
            Log::error("ExportJob {$this->jobId} failed: " . $e->getMessage());
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function generateCsv(array $filters): string
    {
        $path   = 'exports/' . $this->jobId . '.csv';
        $csv    = Writer::createFromString("\xEF\xBB\xBF"); // UTF-8 BOM

        $csv->insertOne([
            'sale_id','branch','sale_date','product_name','category',
            'quantity','unit_price','discount_pct','net_price','revenue',
            'payment_method','salesperson',
        ]);

        Sale::query()
            ->branch($filters['branch'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->category($filters['category'] ?? null)
            ->paymentMethod($filters['payment_method'] ?? null)
            ->orderBy('sale_date')
            ->chunk(500, function ($rows) use ($csv) {
                foreach ($rows as $row) {
                    $csv->insertOne([
                        $row->sale_id, $row->branch, $row->sale_date->format('Y-m-d'),
                        $row->product_name, $row->category, $row->quantity,
                        $row->unit_price, $row->discount_pct, $row->net_price,
                        $row->revenue, $row->payment_method, $row->salesperson,
                    ]);
                }
            });

        Storage::disk('local')->put($path, $csv->toString());
        return $path;
    }

    private function generateExcel(array $filters): string
    {
        $path        = 'exports/' . $this->jobId . '.xlsx';
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Sales Data
        $sheet1 = $spreadsheet->getActiveSheet()->setTitle('Sales Data');
        $sheet1->fromArray([[
            'sale_id','branch','sale_date','product_name','category',
            'quantity','unit_price','discount_pct','net_price','revenue',
            'payment_method','salesperson',
        ]], null, 'A1');

        $rowIdx = 2;
        Sale::query()
            ->branch($filters['branch'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->category($filters['category'] ?? null)
            ->paymentMethod($filters['payment_method'] ?? null)
            ->orderBy('sale_date')
            ->chunk(500, function ($rows) use ($sheet1, &$rowIdx) {
                foreach ($rows as $row) {
                    $sheet1->fromArray([[
                        $row->sale_id, $row->branch, $row->sale_date->format('Y-m-d'),
                        $row->product_name, $row->category, $row->quantity,
                        $row->unit_price, $row->discount_pct, $row->net_price,
                        $row->revenue, $row->payment_method, $row->salesperson,
                    ]], null, "A$rowIdx");
                    $rowIdx++;
                }
            });

        // Sheet 2: Summary per branch
        $sheet2 = $spreadsheet->createSheet()->setTitle('Summary');
        $sheet2->fromArray([['Branch','Transactions','Total Revenue','Total Quantity']], null, 'A1');
        $summaryIdx = 2;
        Sale::query()
            ->branch($filters['branch'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->selectRaw('branch, COUNT(*) as transactions, SUM(revenue) as revenue, SUM(quantity) as quantity')
            ->groupBy('branch')
            ->orderByDesc('revenue')
            ->get()
            ->each(function ($row) use ($sheet2, &$summaryIdx) {
                $sheet2->fromArray([[$row->branch, $row->transactions, $row->revenue, $row->quantity]], null, "A$summaryIdx");
                $summaryIdx++;
            });

        $tmpFile = tempnam(sys_get_temp_dir(), 'shopease_export_');
        (new Xlsx($spreadsheet))->save($tmpFile);
        Storage::disk('local')->put($path, file_get_contents($tmpFile));
        unlink($tmpFile);

        return $path;
    }
}
