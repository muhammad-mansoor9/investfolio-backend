<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketRegimeMetric extends Model
{
    protected $table = 'market_regime_metrics';

    protected $fillable = [
        'index_id',
        'date',
        'regime',
        'regime_score',
        'structural_trend',
        'directional_bias',
        'tactical_momentum',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'regime_score' => 'float',
        'metadata' => 'array',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(Index::class);
    }

    public function scopeLatestForIndex($query, string $indexId)
    {
        return $query->where('index_id', $indexId)->orderBy('date', 'desc')->limit(1);
    }

    public function scopeOrderedByDate($query)
    {
        return $query->orderBy('date', 'desc');
    }
}
