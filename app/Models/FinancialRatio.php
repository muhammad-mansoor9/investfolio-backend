<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialRatio extends PgsqlModel
{
    use HasUuid;
    protected $table = 'financial_ratios';

    protected $fillable = [
        'sector_id',
        'ratio_name',
        'ratio_category',
        'ratio_description',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sector this ratio belongs to (null for universal ratios)
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Scope: Get only active ratios
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get universal ratios (applicable to all sectors)
     */
    public function scopeUniversal($query)
    {
        return $query->whereNull('sector_id');
    }

    /**
     * Scope: Get sector-specific ratios
     */
    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }

    /**
     * Scope: Get ratios by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('ratio_category', $category);
    }

    /**
     * Scope: Order by display order from metadata
     */
    public function scopeOrderByDisplayOrder($query)
    {
        return $query->orderByRaw("CAST(metadata->>'display_order' AS INTEGER) ASC");
    }

    /**
     * Check if this is a critical ratio
     */
    public function isCritical(): bool
    {
        return $this->metadata['weight']['critical'] ?? false;
    }

    /**
     * Get benchmark range if available
     */
    public function getBenchmarkRange(): ?array
    {
        return $this->metadata['weight']['benchmark_range'] ?? null;
    }

    /**
     * Get display order
     */
    public function getDisplayOrder(): int
    {
        return (int) ($this->metadata['display_order'] ?? 999);
    }
}
