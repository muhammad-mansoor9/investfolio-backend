<?php

namespace App\Models;

class StocksMomentum extends PgsqlModel
{
    protected $table = 'stock_signals';
    protected $fillable = [
        'stock_id',
        'symbol',
        'strategy',
        'signal_name',
        'timeframe',
        'signal_state',
        'signal_date',
        'signal_value',
        'metadata',
    ];

    protected $casts = [
        'signal_date' => 'date',
        'metadata' => 'json',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
