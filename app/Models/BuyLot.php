<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuyLot extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'id',
        'portfolio_id',
        'stock_id',
        'transaction_id',
        'price',
        'quantity',
        'remaining_quantity',
        'date',
    ];

    protected $casts = [
        'id' => 'string',
        'portfolio_id' => 'string',
        'transaction_id' => 'string',
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'date' => 'date',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function completedTrades(): HasMany
    {
        return $this->hasMany(CompletedTrade::class);
    }
}
