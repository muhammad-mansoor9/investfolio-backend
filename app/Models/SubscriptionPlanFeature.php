<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// usage_limit NULL means unlimited; a feature with no row is locked on that plan.
class SubscriptionPlanFeature extends PgsqlModel
{
    use HasUuids;

    protected $table = 'subscription_plan_features';

    protected $fillable = [
        'plan_id',
        'feature_id',
        'usage_limit',
        'display_text',
        'sort_order',
    ];

    protected $casts = [
        'usage_limit' => 'integer',
        'sort_order' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(SubscriptionFeature::class, 'feature_id');
    }
}
