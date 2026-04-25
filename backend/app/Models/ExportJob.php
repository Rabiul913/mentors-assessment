<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    protected $fillable = [
        'job_id',
        'status',
        'format',
        'filters',
        'file_path',
        'error_message',
    ];

    protected $casts = [
        'filters' => 'array',
    ];
}