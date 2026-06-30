<?php

declare(strict_types=1);

namespace App\Models;

class DividendHistory extends PgsqlModel
{
    protected $table = 'dividend_history';

    protected $fillable = [
        'stock_id',
        'pay_date',
        'ex_date',
        'type',
        'amount',
        'amount_split_adj'
    ];

    protected $casts = [
        'pay_date' => 'date',
        'ex_date' => 'date',
        'amount' => 'decimal:2',
        'amount_split_adj' => 'decimal:2',
    ];

    // Relationships
    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    // Scopes
    public function scopeForStock($query, $stockId)
    {
        return $query->where('stock_id', $stockId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('pay_date', [$startDate, $endDate]);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('pay_date', '>=', now()->toDateString())
            ->orderBy('pay_date', 'asc');
    }

    public function scopeOrderByPayDate($query, $direction = 'desc')
    {
        return $query->orderBy('pay_date', $direction);
    }

    public function scopeOrderByExDate($query, $direction = 'desc')
    {
        return $query->orderBy('ex_date', $direction);
    }
}
