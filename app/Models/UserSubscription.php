<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UserSubscription extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_price_id',
        'coupon_id',
        'status',
        'start_date',
        'end_date',
        'trial_ends_at',
        'next_billing_date',
        'is_free_trial',
        'auto_renew',
        'payment_method',
        'admin_notes',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'trial_ends_at' => 'date',
        'next_billing_date' => 'date',
        'is_free_trial' => 'boolean',
        'auto_renew' => 'boolean',
        'metadata' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function planPrice()
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'plan_price_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method');
    }

    public function coupon()
    {
        return $this->belongsTo(SubscriptionCoupon::class);
    }

    public function couponRedemptions()
    {
        return $this->belongsTo(SubscriptionCouponRedemption::class);
    }

    public function transactions()
    {
        return $this->hasMany(SubscriptionTransaction::class, 'subscription_id');
    }

    public function featureUsages()
    {
        return $this->hasMany(SubscriptionFeatureUsage::class, 'subscription_id');
    }

    // Subscription creation validation
    public static function canCreateSubscription($userId, $isFreeTrial = false)
    {
        $user = User::find($userId);
        if (!$user) {
            return [
                'can_create' => false,
                'reason' => 'User not found'
            ];
        }

        // Check for active subscriptions
        $activeSubscription = self::where('user_id', $userId)
            ->whereIn('status', ['active', 'trial'])
            ->where(function($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', Carbon::now());
            })
            ->first();

        if ($activeSubscription) {
            return [
                'can_create' => false,
                'reason' => 'User already has an active subscription',
                'existing_subscription' => $activeSubscription
            ];
        }

        // If requesting free trial, check if user has already used free trial
        if ($isFreeTrial) {
            $hasUsedFreeTrial = self::where('user_id', $userId)
                ->where('is_free_trial', true)
                ->exists();

            if ($hasUsedFreeTrial) {
                return [
                    'can_create' => false,
                    'reason' => 'User has already used free trial'
                ];
            }
        }

        return [
            'can_create' => true,
            'reason' => 'User can create subscription'
        ];
    }

    // Instance method version
    public function canCreateSubscriptionForUser($isFreeTrial = false)
    {
        return self::canCreateSubscription($this->user_id, $isFreeTrial);
    }

    // Helper method to check if user has active subscription
    public static function hasActiveSubscription($userId)
    {
        return self::where('user_id', $userId)
            ->whereIn('status', ['active', 'trial'])
            ->where(function($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', Carbon::now());
            })
            ->exists();
    }

    // Helper method to check if user has used free trial
    public static function hasUsedFreeTrial($userId)
    {
        return self::where('user_id', $userId)
            ->where('is_free_trial', true)
            ->exists();
    }

    // Status check methods
    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isTrial()
    {
        return $this->status === 'trial';
    }

    public function isExpired()
    {
        return $this->status === 'expired';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    // Date check methods
    public function isTrialExpired()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isSubscriptionExpired()
    {
        return $this->end_date && $this->end_date->isPast();
    }

    public function daysUntilExpiry()
    {
        if (!$this->end_date) {
            return null;
        }

        return Carbon::now()->diffInDays($this->end_date, false);
    }

    // Scope methods
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeTrial($query)
    {
        return $query->where('status', 'trial');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }
}
