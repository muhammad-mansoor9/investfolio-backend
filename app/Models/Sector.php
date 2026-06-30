<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends PgsqlModel
{
    use HasUuid;

    protected $fillable = ['name', 'total_stocks'];

    /**
     * Get the stocks for the sector.
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function automationConfig(): HasMany
    {
        return $this->hasMany(SectorAutomationConfig::class, 'sector_id', 'id');
    }

    public function sectorParameterValue(): HasMany
    {
        return $this->hasMany(SectorParameterValue::class, 'sector_id', 'id');
    }

    /**
     * Get active stocks in this sector
     */
    public function activeStocks(): HasMany
    {
        return $this->hasMany(Stock::class)->where('is_active', true);
    }

    /**
     * Get sector-specific financial ratios
     */
    public function financialRatios(): HasMany
    {
        return $this->hasMany(FinancialRatio::class);
    }

    /**
     * Get all applicable ratios (universal + sector-specific)
     */
    public function applicableRatios()
    {
        return FinancialRatio::active()
            ->where(function ($query) {
                $query->whereNull('sector_id')
                    ->orWhere('sector_id', $this->id);
            })
            ->orderBy('ratio_category', 'asc')
            ->orderByDisplayOrder()
            ->get();
    }

    /**
     * Get active stocks count
     */
    public function getActiveStocksCountAttribute(): int
    {
        return $this->activeStocks()->count();
    }
}
