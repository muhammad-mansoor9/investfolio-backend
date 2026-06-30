<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionCoupon extends PgsqlModel
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'is_active',
        'max_uses',
        'used_count',
        'valid_from',
        'valid_until',
        'applies_to_plan_id',
        'duration_in_cycles'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'duration_in_cycles' => 'integer'
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'applies_to_plan_id');
    }

    public function redemptions()
    {
        return $this->hasMany(SubscriptionCouponRedemption::class, 'coupon_id');
    }

    /**
     * Check if the coupon is valid for use
     *
     * @return bool
     */
    public function isValid()
    {
        $now = now();

        // Check if active
        if (!$this->is_active) {
            return false;
        }

        // Check valid dates
        if ($now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        // Check max uses
        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Get the duration description
     *
     * @return string
     */
    public function getDurationDescription()
    {
        if ($this->duration_in_cycles === null) {
            return 'Forever';
        }

        if ($this->duration_in_cycles === 1) {
            return '1 billing cycle';
        }

        return $this->duration_in_cycles . ' billing cycles';
    }
}
