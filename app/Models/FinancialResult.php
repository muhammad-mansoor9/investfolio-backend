<?php

declare(strict_types=1);

namespace App\Models;

class FinancialResult extends PgsqlModel
{
    protected $table = 'financial_results';

    protected $fillable = [
        'stock_id',
        'announcement_date',
        'year_end',
        'quarter',
        'eps',
        'summary',
        'resource_url',
    ];

    protected $casts = [
        'stock_id' => 'string',
        'announcement_date' => 'date',
        'eps' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id', 'id');
    }

    public function scopeByQuarter($query, $quarter)
    {
        return $query->where('quarter', $quarter);
    }

    public function scopeHasEps($query)
    {
        return $query->whereNotNull('eps')->where('eps', '>', 0);
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('announcement_date', 'desc');
    }
}
