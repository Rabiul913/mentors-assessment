<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Sale extends Model
{
    protected $fillable = [
        'sale_id',
        'branch',
        'sale_date',
        'product_name',
        'category',
        'quantity',
        'unit_price',
        'discount_pct',
        'net_price',          // unit_price * (1 - discount_pct)
        'revenue',            // net_price * quantity
        'payment_method',
        'salesperson',
        'raw_row_hash',       // SHA-256 of original row — used for duplicate detection
    ];

    protected $casts = [
        'sale_date'    => 'date',
        'unit_price'   => 'decimal:2',
        'discount_pct' => 'decimal:4',
        'net_price'    => 'decimal:2',
        'revenue'      => 'decimal:2',
        'quantity'     => 'integer',
    ];

    public function scopeBranch(Builder $q, ?string $branch): Builder
    {
        return $branch ? $q->where('branch', $branch) : $q;
    }

    public function scopeDateRange(Builder $q, ?string $from, ?string $to): Builder
    {
        if ($from) $q->where('sale_date', '>=', $from);
        if ($to)   $q->where('sale_date', '<=', $to);
        return $q;
    }

    public function scopeCategory(Builder $q, ?string $cat): Builder
    {
        return $cat ? $q->where('category', $cat) : $q;
    }

    public function scopePaymentMethod(Builder $q, ?string $method): Builder
    {
        return $method ? $q->where('payment_method', $method) : $q;
    }
}
