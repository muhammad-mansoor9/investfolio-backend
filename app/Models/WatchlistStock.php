<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Traits\HasUuid;

class WatchlistStock extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'watchlist_id',
        'watchable_type',
        'watchable_id',
        'section_id',
        'sort_order',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(WatchlistSection::class);
    }

    public function watchable(): MorphTo
    {
        return $this->morphTo();
    }

    // Helper methods
    public function getSymbol(): string
    {
        return $this->watchable->symbol;
    }

    public function getName(): string
    {
        return $this->watchable->name ?? $this->watchable->description;
    }

    public function getLatestPrice()
    {
        return $this->watchable->latestPrice;
    }

    public function isStock(): bool
    {
        return $this->watchable_type === Stock::class;
    }

    public function isIndex(): bool
    {
        return $this->watchable_type === Index::class;
    }

    public function getType(): string
    {
        return $this->isStock() ? 'stock' : 'index';
    }
}
