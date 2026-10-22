<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends PgsqlModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'trial_days',
        'is_active',
        'is_default',
        'is_featured',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'trial_days' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionFeature::class, 'subscription_plan_features', 'plan_id', 'feature_id')
            ->withPivot(['usage_limit', 'display_text', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function default(): self
    {
        return static::where('is_default', true)->firstOrFail();
    }
}
