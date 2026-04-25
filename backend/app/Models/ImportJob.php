<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'job_id',
        'status',
        'total_rows',
        'inserted',
        'skipped_duplicates',
        'skipped_invalid',
        'error_log_path',
        'error_message',
    ];

    protected $casts = [
        'total_rows'        => 'integer',
        'inserted'          => 'integer',
        'skipped_duplicates'=> 'integer',
        'skipped_invalid'   => 'integer',
    ];
}