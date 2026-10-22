<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionTransaction extends PgsqlModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'subscription_transactions';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'amount',
        'currency',
        'payment_status',
        'paddle_transaction_id',
        'billing_period_start',
        'billing_period_end',
        'invoice_number',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'billing_period_start' => 'datetime',
        'billing_period_end' => 'datetime',
        'metadata' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
