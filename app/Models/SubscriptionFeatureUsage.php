<?php

namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SubscriptionFeatureUsage extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $table = 'subscription_feature_usage';

    protected $fillable = [
        'subscription_id',
        'feature_id',
        'usage_count',
    ];

    protected $casts = [
        'usage_count' => 'integer',
    ];

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    public function feature()
    {
        return $this->belongsTo(SubscriptionFeature::class, 'feature_id');
    }

    public function incrementUsage($count = 1)
    {
        $this->usage_count += $count;
        $this->save();

        return $this;
    }

    public function resetUsage()
    {
        $this->usage_count = 0;
        $this->save();

        return $this;
    }

    public function hasReachedLimit()
    {
        if (!$this->feature->track_usage) {
            return false;
        }

        $planFeature = SubscriptionPlanFeature::where('plan_id', $this->subscription->plan_id)
            ->where('feature_id', $this->feature_id)
            ->first();

        if (!$planFeature) {
            return true;
        }

        if ($planFeature->usage_limit === null) {
            return false;
        }

        return $this->usage_count >= $planFeature->usage_limit;
    }
}
