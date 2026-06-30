<?php

declare(strict_types=1);

namespace App\Models;



use App\Traits\HasUuid;

class Stock extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'market_id', 'exchange_id', 'sector_id', 'asset_id',
        'symbol', 'description', 'is_shariah', 'is_active',
        'market_cap', 'total_shares_outstanding', 'free_float', 'face_value'
    ];

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function exchange()
    {
        return $this->belongsTo(ExchangeMarket::class, 'exchange_id');
    }

    public function prices()
    {
        return $this->hasMany(StockPrice::class);
    }

    public function latestPrice()
    {
        return $this->hasOne(StockPrice::class)->latestOfMany('date');
    }

    public function buyLots()
    {
        return $this->hasMany(BuyLot::class);
    }

    // Method to get available buy lots for FIFO selling (ordered by date)
    public function availableBuyLots()
    {
        return $this->buyLots()
            ->where('remaining_quantity', '>', 0)
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc');
    }

    // Method to get buy lots for a specific portfolio
    public function buyLotsForPortfolio($portfolioId)
    {
        return $this->buyLots()->where('portfolio_id', $portfolioId);
    }

    // Method to get available buy lots for a specific portfolio (for FIFO)
    public function availableBuyLotsForPortfolio($portfolioId)
    {
        return $this->buyLotsForPortfolio($portfolioId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc');
    }
}
