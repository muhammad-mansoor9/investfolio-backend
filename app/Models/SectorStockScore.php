<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorStockScore extends Model
{
    protected $table = 'sector_stock_scores';

    protected $fillable = [
        'stock_id',
        'sector_id',
        'date',
        'relative_leadership_score',
        'trend_structure_score',
        'momentum_score',
        'participation_score',
        'stock_strength_score',
        'watch_score',
        'simple_state',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'relative_leadership_score' => 'float',
        'trend_structure_score' => 'float',
        'momentum_score' => 'float',
        'participation_score' => 'float',
        'stock_strength_score' => 'float',
        'watch_score' => 'float',
        'metadata' => 'array',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function scopeForStock($query, string $stockId)
    {
        return $query->where('stock_id', $stockId);
    }

    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }

    public function scopeBySimpleState($query, string $state)
    {
        return $query->where('simple_state', $state);
    }

    public function scopeByWatchScore($query)
    {
        return $query->orderBy('watch_score', 'desc');
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('date', 'desc')->limit(1);
    }

    public function scopeOrderedByDate($query)
    {
        return $query->orderBy('date', 'desc');
    }
}
