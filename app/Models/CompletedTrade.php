<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletedTrade extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'id',
        'portfolio_id',
        'stock_id',
        'buy_lot_id',
        'sell_transaction_id',
        'quantity',
        'buy_price',
        'sell_price',
        'profit',
        'buy_date',
        'sell_date',
        'holding_days',
    ];

    protected $casts = [
        'id' => 'string',
        'portfolio_id' => 'string',
        'buy_lot_id' => 'string',
        'sell_transaction_id' => 'string',
        'quantity' => 'integer',
        'buy_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'buy_date' => 'date',
        'sell_date' => 'date',
        'holding_days' => 'integer',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function buyLot(): BelongsTo
    {
        return $this->belongsTo(BuyLot::class);
    }

    public function sellTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'sell_transaction_id');
    }
}
