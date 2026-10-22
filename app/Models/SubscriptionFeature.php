<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
}
