<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class SubscriptionPlan extends PgsqlModel
{
    use SoftDeletes, HasUuid, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'trial_days',
        'is_active',
        'sort_order',
        'metadata'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array'
    ];

    public function prices()
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id');
    }

    public function activePrices()
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id')->where('is_active', true);
    }

    public function features()
    {
        return $this->belongsToMany(SubscriptionFeature::class, 'subscription_plan_features', 'plan_id', 'feature_id')
            ->withPivot('usage_limit')
            ->withTimestamps();
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class, 'plan_id');
    }

    public function getPriceForCycle($billingCycle)
    {
        return $this->prices()->where('billing_cycle', $billingCycle)->where('is_active', true)->first();
    }

    public function getLowestPrice()
    {
        return $this->activePrices()->orderBy('price', 'asc')->first();
    }

    public function getHighestPrice()
    {
        return $this->activePrices()->orderBy('price', 'desc')->first();
    }

    public function getFreeTrialAvailableAttribute($value)
    {
        return $this->trial_days > 0;
    }

    // Override the setAttribute to prevent manual setting
    public function setFreeTrialAvailableAttribute($value)
    {
        // Don't allow manual setting - it's determined by trial_days
    }
}
