<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanPrice extends PgsqlModel
{
    use HasUuids;

    protected $table = 'subscription_plan_prices';

    protected $fillable = [
        'plan_id',
        'billing_cycle',
        'price',
        'currency',
        'is_active',
        'paddle_price_id',
        'metadata',
    ];

    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->paddle_price_id !== null && $this->plan && !$this->plan->is_default && $this->plan->is_active;
    }

    public function label(): string
    {
        return $this->plan->name . ' · ' . ucfirst(str_replace('_', '-', $this->billing_cycle));
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'billing_cycle' => $this->billing_cycle,
            'price' => $this->price,
            'currency' => trim($this->currency),
            'paddle_price_id' => $this->paddle_price_id,
        ];
    }
}
