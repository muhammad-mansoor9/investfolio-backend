<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deduction extends PgsqlModel
{
    use HasUuid, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'portfolio_id',
        'stock_id',
        'trade_id',
        'completed_trade_id',
        'dividend_id',
        'type',
        'description',
        'amount',
        'tax_rate',
        'calculation_base',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Define relationships (e.g., to BasePortfolio, Stock, Trade, CompletedTrade, Dividend)
    public function portfolio()
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class);
    }

    public function completedTrade()
    {
        return $this->belongsTo(CompletedTrade::class);
    }

    public function dividend()
    {
        return $this->belongsTo(Dividend::class);
    }
}

