<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parameter extends Model
{
    use HasFactory;

    protected $table = 'parameters';

    protected $primaryKey = 'parameter_id';

    public $timestamps = false;

    protected $fillable = [
        'parameter_coe',
        'parameter_name',
        'api_param_name',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get all values for this parameter
     */
    public function values(): HasMany
    {
        return $this->hasMany(ParameterValue::class, 'parameter_id', 'parameter_id');
    }

    /**
     * Scope to get by parameter code
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('parameter_coe', $code);
    }

    /**
     * Scope to get by API parameter name
     */
    public function scopeByApiParam($query, string $apiParam)
    {
        return $query->where('api_param_name', $apiParam);
    }

    /**
     * Check if parameter is static (Frequency, Parameter, Growth)
     */
    public function isStatic(): bool
    {
        return in_array($this->parameter_coe, ['FREQUENCY', 'PARAMETER', 'GROWTH']);
    }

    /**
     * Check if parameter is dynamic (Company, Type, Category, Product)
     */
    public function isDynamic(): bool
    {
        return in_array($this->parameter_coe, ['COMPANY', 'TYPE', 'CATEGORY', 'PRODUCT', 'INDICES', 'SALESTAB']);
    }
}
