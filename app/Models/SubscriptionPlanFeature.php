<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanFeature extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'plan_id',
        'feature_id',
        'usage_limit'
    ];

    protected $casts = [
        'usage_limit' => 'integer'
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function feature()
    {
        return $this->belongsTo(SubscriptionFeature::class, 'feature_id');
    }

    public function getIsUnlimitedAttribute()
    {
        return is_null($this->usage_limit);
    }

    public function getFormattedLimitAttribute()
    {
        return $this->usage_limit ? number_format($this->usage_limit) : 'Unlimited';
    }
}

