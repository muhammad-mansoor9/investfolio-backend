<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends PgsqlModel
{
    use HasUuids;

    protected $table = 'user_subscriptions';

    // Keep microseconds: last_event_at orders Paddle entity versions that can land in the same second.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_price_id',
        'status',
        'starts_at',
        'current_period_end',
        'paddle_subscription_id',
        'cancel_at_period_end',
        'canceled_at',
        'last_event_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'last_event_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'plan_price_id');
    }

    /**
     * A paid Paddle subscription exists (in any non-canceled state, including paused).
     */
    public function hasPaddleSubscription(): bool
    {
        return $this->paddle_subscription_id !== null && !$this->plan->is_default;
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused' && $this->hasPaddleSubscription();
    }

    /**
     * Paid features are currently granted: a purchased plan, or one granted directly without Paddle.
     * A paused subscription keeps its plan but not its features.
     */
    public function isPaid(): bool
    {
        return !$this->plan->is_default && !$this->isPaused();
    }
}
