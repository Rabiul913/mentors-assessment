<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\ExportController;

Route::prefix('v1')->group(function () {

    // --- Import Routes ---
    Route::prefix('import')->controller(ImportController::class)->group(function () {
        Route::post('/', 'upload');
        Route::get('/{jobId}/status', 'status');
    });

    // --- Sales Routes ---
    Route::prefix('sales')->controller(SalesController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/summary', 'summary');
    });

    // --- Export Routes ---
    Route::prefix('export')->controller(ExportController::class)->group(function () {
        Route::get('/csv', 'csv');
        Route::get('/excel', 'excel');
        Route::get('/{jobId}/status', 'status');
        Route::get('/{jobId}/download', 'download');
    });

});

