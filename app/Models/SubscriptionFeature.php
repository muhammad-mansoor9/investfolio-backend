<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionFeature extends PgsqlModel
{
    use HasUuids;

    protected $table = 'subscription_features';

    protected $fillable = [
        'feature_key',
        'name',
        'description',
        'icon',
        'track_usage',
        'sort_order',
    ];

    protected $casts = [
        'track_usage' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function planFeatures(): HasMany
    {
        return $this->hasMany(SubscriptionPlanFeature::class, 'feature_id');
    }
}
