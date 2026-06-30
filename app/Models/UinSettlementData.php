<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UinSettlementData extends PgsqlModel
{
    protected $table = 'uin_settlement_data';

    protected $fillable = [
        'stock_id',
        'symbol',
        'trade_volume',
        'trade_value',
        'uin_settlement_volume',
        'uin_settlement_value',
        'uin_percentage_volume',
        'uin_percentage_value',
        'settlement_date',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'trade_volume' => 'decimal:2',
        'trade_value' => 'decimal:2',
        'uin_settlement_volume' => 'decimal:2',
        'uin_settlement_value' => 'decimal:2',
        'uin_percentage_volume' => 'decimal:2',
        'uin_percentage_value' => 'decimal:2',
        'settlement_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relationship with Stock
     */
    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('settlement_date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by stock
     */
    public function scopeForStock($query, $stockId)
    {
        return $query->where('stock_id', $stockId);
    }

    /**
     * Scope to order by date
     */
    public function scopeOrderByDate($query, $direction = 'desc')
    {
        return $query->orderBy('settlement_date', $direction);
    }
}
