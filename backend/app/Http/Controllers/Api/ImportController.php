<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCsvImportJob;
use App\Models\ImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    /**
     * POST /api/v1/import
     * Accept CSV/Excel upload, dispatch background job, return job_id.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:51200', // 50 MB
        ]);

        $file   = $request->file('file');
        $jobId  = Str::uuid()->toString();

        // Store file in a private temp area
        $storedPath = $file->storeAs('imports', $jobId . '_' . $file->getClientOriginalName(), 'local');

        // Create tracking record
        $importJob = ImportJob::create([
            'job_id' => $jobId,
            'status' => 'pending',
        ]);

        // Dispatch the heavy-lifting job
        ProcessCsvImportJob::dispatch($jobId, $storedPath);

        return response()->json([
            'message' => 'Import queued',
            'job_id'  => $jobId,
            'status'  => 'pending',
            'poll_url' => url("/api/v1/import/{$jobId}/status"),
        ], 202);
    }

    /**
     * GET /api/v1/import/{jobId}/status
     */
    public function status(string $jobId): JsonResponse
    {
        $job = ImportJob::where('job_id', $jobId)->firstOrFail();

        $response = [
            'job_id'            => $job->job_id,
            'status'            => $job->status,
            'total_rows'        => $job->total_rows,
            'inserted'          => $job->inserted,
            'skipped_duplicates'=> $job->skipped_duplicates,
            'skipped_invalid'   => $job->skipped_invalid,
        ];

        if ($job->status === 'done' && $job->error_log_path) {
            $response['error_log_url'] = url("/api/v1/import/{$jobId}/errors");
        }

        if ($job->error_message) {
            $response['error_message'] = $job->error_message;
        }

        return response()->json($response);
    }
}
