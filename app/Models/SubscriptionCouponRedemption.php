<?php

namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionCouponRedemption extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'coupon_id',
        'applied_on',
        'cycles_remaining',
    ];

    protected $casts = [
        'applied_on' => 'datetime',
        'cycles_remaining' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    public function coupon()
    {
        return $this->belongsTo(SubscriptionCoupon::class, 'coupon_id');
    }

    public function isActive()
    {
        return $this->cycles_remaining === null || $this->cycles_remaining > 0;
    }

    public function isExpired()
    {
        return $this->cycles_remaining !== null && $this->cycles_remaining <= 0;
    }
}

