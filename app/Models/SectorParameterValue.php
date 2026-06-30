<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorParameterValue extends Model
{
    use HasFactory;

    protected $table = 'sector_parameter_values';

    public $timestamps = false;

    protected $fillable = [
        'sector_id',
        'value_id',
        'default',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'default' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Get the sector that owns this parameter value
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Get the parameter value
     */
    public function parameterValue(): BelongsTo
    {
        return $this->belongsTo(ParameterValue::class, 'value_id', 'value_id');
    }

    /**
     * Scope to get only active values
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get default values
     */
    public function scopeDefault($query)
    {
        return $query->where('default', true);
    }

    /**
     * Scope to get by sector ID
     */
    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }

    /**
     * Scope to order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc');
    }
}
