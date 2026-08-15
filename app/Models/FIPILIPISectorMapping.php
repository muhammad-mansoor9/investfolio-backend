<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FIPILIPISectorMapping extends Model
{
    protected $table = 'fipi_lipi_sector_mappings';

    protected $fillable = [
        'external_sector_name',
        'sector_id',
        'is_aggregate',
        'notes',
    ];

    protected $casts = [
        'is_aggregate' => 'boolean',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function scopeByExternalName($query, string $name)
    {
        return $query->where('external_sector_name', $name);
    }

    public function scopeMapped($query)
    {
        return $query->whereNotNull('sector_id');
    }

    public function scopeUnmapped($query)
    {
        return $query->whereNull('sector_id');
    }
}
