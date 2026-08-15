<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorRotationMetric extends Model
{
    protected $table = 'sector_rotation_metrics';

    protected $fillable = [
        'sector_id',
        'benchmark_index_id',
        'date',
        'status',
        'status_since_date',
        'trading_sessions_in_status',
        'rs_vs_kse100',
        'rs_vs_allshr',
        'rs_ratio',
        'rs_momentum',
        'direction',
        'sector_strength',
        'breadth_ema_participation',
        'breadth_rsi_participation',
        'breadth_macd_participation',
        'breadth_di_participation',
        'participation_free_float_vs_ew',
        'participation_volume_ratio',
        'participation_uin_settlement_pct',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'status_since_date' => 'date',
        'rs_vs_kse100' => 'float',
        'rs_vs_allshr' => 'float',
        'rs_ratio' => 'float',
        'rs_momentum' => 'float',
        'sector_strength' => 'float',
        'breadth_ema_participation' => 'float',
        'breadth_rsi_participation' => 'float',
        'breadth_macd_participation' => 'float',
        'breadth_di_participation' => 'float',
        'participation_free_float_vs_ew' => 'float',
        'participation_volume_ratio' => 'float',
        'participation_uin_settlement_pct' => 'float',
        'metadata' => 'array',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function benchmarkIndex(): BelongsTo
    {
        return $this->belongsTo(Index::class, 'benchmark_index_id');
    }

    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }

    public function scopeVsBenchmark($query, string $benchmarkId)
    {
        return $query->where('benchmark_index_id', $benchmarkId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeAfterDate($query, $date)
    {
        return $query->where('date', '>=', $date);
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
