<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

class WatchlistSection extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'watchlist_id', 'name', 'description', 'color', 'sort_order', 'is_collapsed'
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_collapsed' => 'boolean',
    ];

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WatchlistStock::class, 'section_id')
            ->orderBy('sort_order');
    }
}
