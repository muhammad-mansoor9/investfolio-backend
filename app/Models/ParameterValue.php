<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParameterValue extends Model
{
    use HasFactory;

    protected $table = 'parameter_values';

    protected $primaryKey = 'value_id';

    public $timestamps = false;

    protected $fillable = [
        'parameter_id',
        'value_code',
        'value_label',
        'api_value',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the parameter that owns this value
     */
    public function parameter(): BelongsTo
    {
        return $this->belongsTo(Parameter::class, 'parameter_id', 'parameter_id');
    }

    /**
     * Get sector parameter values
     */
    public function sectorParameterValues(): HasMany
    {
        return $this->hasMany(SectorParameterValue::class, 'value_id', 'value_id');
    }

    /**
     * Scope to get by parameter ID
     */
    public function scopeForParameter($query, int $parameterId)
    {
        return $query->where('parameter_id', $parameterId);
    }

    /**
     * Scope to get by value code
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('value_code', $code);
    }

    /**
     * Scope to get by API value
     */
    public function scopeByApiValue($query, string $apiValue)
    {
        return $query->where('api_value', $apiValue);
    }
}
