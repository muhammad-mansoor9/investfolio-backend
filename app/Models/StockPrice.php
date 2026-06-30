<?php

declare(strict_types=1);

namespace App\Models;

class StockPrice extends PgsqlModel
{
    protected $fillable = ['stock_id', 'date', 'open', 'high', 'low', 'close', 'price', 'change', 'volume', 'change_percent'];

    protected $casts = ['date' => 'datetime'];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
