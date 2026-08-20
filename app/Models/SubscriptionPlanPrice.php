<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanPrice extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'billing_cycle',
        'price',
        'currency',
        'is_active',
        'stripe_price_id',
        'metadata'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array'
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class, 'plan_price_id');
    }

    public function getBillingCycleLabelAttribute()
    {
        $labels = [
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semi_annual' => 'Semi-Annual',
            'annual' => 'Annual'
        ];

        return $labels[$this->billing_cycle] ?? $this->billing_cycle;
    }

    public function getFormattedPriceAttribute()
    {
        return number_format($this->price, 2);
    }
}

