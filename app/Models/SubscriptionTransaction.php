<?php

namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionTransaction extends PgsqlModel
{
    use HasUuid, HasFactory, SoftDeletes;

    protected $fillable = [
        'subscription_id',
        'payment_method_id',
        'user_id',
        'amount',
        'currency',
        'payment_status',
        'transaction_reference',
        'invoice_number',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'metadata' => 'array',
    ];

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method');
    }

    public function isPending()
    {
        return $this->payment_status === 'pending';
    }

    public function isCompleted()
    {
        return $this->payment_status === 'completed';
    }

    public function isFailed()
    {
        return $this->payment_status === 'failed';
    }

    public function isRefunded()
    {
        return $this->payment_status === 'refunded';
    }
}
// Sync marker: 2026-08-20 17:39:39
