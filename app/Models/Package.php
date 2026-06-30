<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid;

class Package extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'name', 'description', 'paddle_product_id', 'price', 'billing_cycle', 'currency',
        'max_watchlists', 'max_stocks_per_watchlist', 'max_chart_layouts',
        'is_premium', 'is_active'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_watchlists' => 'integer',
        'max_stocks_per_watchlist' => 'integer',
        'max_chart_layouts' => 'integer',
        'is_premium' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class)->where('status', 'active');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    public function scopeBasic($query)
    {
        return $query->where('price', 0.00)->where('is_premium', false);
    }

    public function scopeWithPaddle($query)
    {
        return $query->whereNotNull('paddle_product_id');
    }

    public function isFree(): bool
    {
        return $this->price == 0.00;
    }

    public function isBasic(): bool
    {
        return $this->price == 0.00 && !$this->is_premium;
    }

    public static function getBasicPackage()
    {
        return static::basic()->active()->first();
    }
}
