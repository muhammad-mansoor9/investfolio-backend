<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceCriteria extends PgsqlModel
{
    use HasUuid;

    protected $table = 'performance_criteria';

    protected $fillable = [
        'sector_id',
        'criteria_name',
        'criteria_category',
        'criteria_description',
        'criteria_config',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'criteria_config' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sector this criteria belongs to (null for universal criteria)
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Scope: Get only active criteria
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get universal criteria (applicable to all sectors)
     */
    public function scopeUniversal($query)
    {
        return $query->whereNull('sector_id');
    }

    /**
     * Scope: Get sector-specific criteria
     */
    public function scopeForSector($query, string $sectorId)
    {
        return $query->where(function ($q) use ($sectorId) {
            $q->whereNull('sector_id')
                ->orWhere('sector_id', $sectorId);
        });
    }

    /**
     * Scope: Get criteria by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('criteria_category', $category);
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrderByDisplayOrder($query)
    {
        return $query->orderBy('display_order', 'asc');
    }

    /**
     * Get calculation method
     */
    public function getCalculationMethod(): ?string
    {
        return $this->criteria_config['calculation_method'] ?? null;
    }

    /**
     * Get weightage
     */
    public function getWeightage(): float
    {
        return (float) ($this->criteria_config['weightage'] ?? 1.0);
    }

    /**
     * Get scoring type
     */
    public function getScoringType(): ?string
    {
        return $this->criteria_config['scoring_type'] ?? null;
    }

    /**
     * Get reference values
     */
    public function getReferenceValues(): ?array
    {
        return $this->criteria_config['reference_values'] ?? null;
    }

    /**
     * Is higher value better?
     */
    public function isHigherBetter(): bool
    {
        return $this->criteria_config['is_higher_better'] ?? true;
    }

    /**
     * Get unit
     */
    public function getUnit(): string
    {
        return $this->criteria_config['unit'] ?? '';
    }
}
