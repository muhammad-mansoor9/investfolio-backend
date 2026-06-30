<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends PgsqlModel
{
    protected $fillable = [
        'symbol', 'portfolio_id', 'stock_id', 'type',
        'date', 'quantity', 'amount', 'note'
    ];

    protected $casts = [
        'id' => 'string',
        'portfolio_id' => 'string',
        'stock_id' => 'integer',
        'quantity' => 'integer',
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function portfolio()
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
