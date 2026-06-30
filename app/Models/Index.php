<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class Index extends PgsqlModel
{
    use HasUuid;

    protected $table = 'indices';
    protected $fillable = [
        'market_id',
        'exchange_id',
        'symbol',
        'description',
        'total_stocks'
    ];

    protected $casts = [
        'total_stocks' => 'integer',
    ];

    // Relationships
    public function exchange()
    {
        return $this->belongsTo(ExchangeMarket::class, 'exchange_id');
    }

    public function prices()
    {
        return $this->hasMany(IndexPrice::class);
    }

    public function latestPrice()
    {
        return $this->hasOne(IndexPrice::class)->latestOfMany('date');
    }

    public function stocks()
    {
        return $this->belongsToMany(Stock::class, 'index_stocks')
            ->withPivot(['weightage', 'created_at', 'updated_at']);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithLatestPrice($query)
    {
        return $query->with('latestPrice');
    }

    public function scopeWithStocks($query)
    {
        return $query->with(['stocks' => function ($query) {
            $query->orderBy('index_stocks.weightage', 'desc');
        }]);
    }

    public function scopeByExchange($query, $exchangeId)
    {
        return $query->where('exchange_id', $exchangeId);
    }
}
