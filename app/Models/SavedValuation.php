<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class SavedValuation extends PgsqlModel
{
    use HasUuid;

    protected $table = 'saved_valuations';

    protected $fillable = [
        'user_id',
        'stock_id',
        'stock_symbol',
        'name',
        'year_label',
        'eps',
        'pe',
        'revenue_growth',
        'gross_profit',
        'dps',
        'current_price',
        'sector_pe',
        'fair_value',
        'upside_pct',
        'outlook',
        'signal_score',
        'signals',
    ];

    protected $casts = [
        'signals' => 'json',
        'eps' => 'decimal:4',
        'pe' => 'decimal:2',
        'revenue_growth' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'dps' => 'decimal:4',
        'current_price' => 'decimal:4',
        'sector_pe' => 'decimal:2',
        'fair_value' => 'decimal:4',
        'upside_pct' => 'decimal:2',
        'signal_score' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
