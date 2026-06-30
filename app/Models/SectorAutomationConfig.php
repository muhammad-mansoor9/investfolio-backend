<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorAutomationConfig extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'sector_automation_config';

    protected $fillable = [
        'sector_id',
        'automation_key',
        'api_endpoint',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sector that owns this automation config
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Scope to get only active configurations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get by automation key
     */
    public function scopeByAutomationKey($query, string $key)
    {
        return $query->where('automation_key', $key);
    }

    /**
     * Scope to get by sector ID
     */
    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }
}
