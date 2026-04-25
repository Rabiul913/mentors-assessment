<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    private const LARGE_EXPORT_THRESHOLD = 10_000;

    /**
     * GET /api/v1/export/csv
     */
    public function csv(Request $request): JsonResponse
    {
        return $this->handleExport($request, 'csv');
    }

    /**
     * GET /api/v1/export/excel
     */
    public function excel(Request $request): JsonResponse
    {
        return $this->handleExport($request, 'excel');
    }

    private function handleExport(Request $request, string $format): JsonResponse
    {
        $filters = $request->only(['branch', 'from', 'to', 'category', 'payment_method']);

        $count = Sale::query()
            ->branch($filters['branch'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->category($filters['category'] ?? null)
            ->paymentMethod($filters['payment_method'] ?? null)
            ->count();

        // For large exports, dispatch a background job
        if ($count > self::LARGE_EXPORT_THRESHOLD) {
            return $this->dispatchLargeExport($filters, $format);
        }

        // Small export — stream directly
        return $format === 'csv'
            ? $this->streamCsv($filters)
            : $this->streamExcel($filters);
    }

    private function dispatchLargeExport(array $filters, string $format): JsonResponse
    {
        $jobId = Str::uuid()->toString();

        ExportJob::create([
            'job_id'  => $jobId,
            'status'  => 'pending',
            'format'  => $format,
            'filters' => $filters,
        ]);

        ProcessExportJob::dispatch($jobId);

        return response()->json([
            'message'     => 'Large export queued',
            'job_id'      => $jobId,
            'status'      => 'pending',
            'poll_url'    => url("/api/v1/export/{$jobId}/status"),
            'download_url'=> url("/api/v1/export/{$jobId}/download"),
        ], 202);
    }

    /**
     * GET /api/v1/export/{jobId}/status
     */
    public function status(string $jobId): JsonResponse
    {
        $job = ExportJob::where('job_id', $jobId)->firstOrFail();
        return response()->json([
            'job_id'   => $job->job_id,
            'status'   => $job->status,
            'format'   => $job->format,
            'ready'    => $job->status === 'done',
            'download_url' => $job->status === 'done'
                ? url("/api/v1/export/{$jobId}/download")
                : null,
        ]);
    }

    /**
     * GET /api/v1/export/{jobId}/download
     */
    public function download(string $jobId): JsonResponse
    {
        $job = ExportJob::where('job_id', $jobId)->firstOrFail();

        if ($job->status !== 'done' || !$job->file_path) {
            return response()->json(['message' => 'Export not ready'], 404);
        }

        $filename = "shopease_export_{$jobId}.{$job->format}";

        return Storage::disk('local')->download($job->file_path, $filename);
    }

    // ── Streaming helpers ────────────────────────────────────────────────

    private function streamCsv(array $filters): StreamedResponse
    {
        $filename = 'shopease_sales_' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($filters) {
            // UTF-8 BOM for Bengali character compatibility in Excel
            echo "\xEF\xBB\xBF";

            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
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
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->sale_id, $row->branch, $row->sale_date,
                            $row->product_name, $row->category, $row->quantity,
                            $row->unit_price, $row->discount_pct, $row->net_price,
                            $row->revenue, $row->payment_method, $row->salesperson,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    private function streamExcel(array $filters): StreamedResponse
    {
        // Excel export is handled inline for small datasets using PhpSpreadsheet
        $filename = 'shopease_sales_' . now()->format('Y-m-d') . '.xlsx';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Sheet 1: Sales Data
        $sheet1 = $spreadsheet->getActiveSheet()->setTitle('Sales Data');
        $headers = ['sale_id','branch','sale_date','product_name','category',
                    'quantity','unit_price','discount_pct','net_price','revenue',
                    'payment_method','salesperson'];
        $sheet1->fromArray([$headers], null, 'A1');

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

        // Sheet 2: Summary
        $sheet2 = $spreadsheet->createSheet()->setTitle('Summary');
        $sheet2->fromArray([['Branch', 'Transactions', 'Revenue', 'Quantity']], null, 'A1');
        $summaryIdx = 2;
        Sale::query()
            ->branch($filters['branch'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->category($filters['category'] ?? null)
            ->paymentMethod($filters['payment_method'] ?? null)
            ->selectRaw('branch, COUNT(*) as transactions, SUM(revenue) as revenue, SUM(quantity) as quantity')
            ->groupBy('branch')
            ->orderByDesc('revenue')
            ->get()
            ->each(function ($row) use ($sheet2, &$summaryIdx) {
                $sheet2->fromArray([[$row->branch, $row->transactions, $row->revenue, $row->quantity]], null, "A$summaryIdx");
                $summaryIdx++;
            });

        return response()->stream(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
