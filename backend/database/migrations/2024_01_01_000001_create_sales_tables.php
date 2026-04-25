<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_id', 20)->index();          // NOT unique — duplicates are skipped by hash
            $table->string('branch', 60);
            $table->date('sale_date');
            $table->string('product_name', 255);
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_pct', 6, 4)->default(0);
            $table->decimal('net_price', 12, 2);
            $table->decimal('revenue', 14, 2);
            $table->string('payment_method', 50);
            $table->string('salesperson', 100)->default('Unknown');
            $table->string('raw_row_hash', 64)->unique();    // duplicate guard

            $table->timestamps();

            // Query optimisation indices
            $table->index(['branch', 'sale_date']);
            $table->index('sale_date');
            $table->index('category');
            $table->index('payment_method');
        });

        // Import job tracking
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 40)->unique();
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('skipped_duplicates')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->string('error_log_path')->nullable();    // path to error CSV on disk
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // Export job tracking
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 40)->unique();
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->enum('format', ['csv', 'excel']);
            $table->json('filters')->nullable();             // serialised filter params
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('sales');
    }
};
