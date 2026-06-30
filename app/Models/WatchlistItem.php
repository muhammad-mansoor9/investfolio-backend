<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchlistItem extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'watchlist_id',
        'symbol',
        'notes',
    ];

    public function watchlist()
    {
        return $this->belongsTo(Watchlist::class);
    }

    public function market_data()
    {
        return $this->hasOne(MarketData::class, 'symbol', 'symbol');
    }

    public function scopeWithMarketData($query)
    {
        return $query->with('market_data');
    }
}
