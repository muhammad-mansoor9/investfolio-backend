<?php

namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Trade extends PgsqlModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'portfolio_id', 'stock_id', 'type', 'quantity', 'price',
        'amount', 'total_amount', 'date', 'trade_date', 'status', 'note', 'notes'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'date' => 'date',
        'trade_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            // Set amount from total_amount if not set
            if (!$model->amount && $model->total_amount) {
                $model->amount = $model->total_amount;
            }

            // Set date from trade_date if not set
            if (!$model->date && $model->trade_date) {
                $model->date = $model->trade_date;
            }

            // Set user_id from portfolio owner if not set
            if (!$model->user_id && $model->portfolio_id) {
                $portfolio = BasePortfolio::find($model->portfolio_id);
                if ($portfolio) {
                    $owner = $portfolio->owners()->first();
                    if ($owner) {
                        $model->user_id = $owner->id;
                    }
                }
            }

            // Check if user has active subscription before creating trade
            $user = null;
            if ($model->user_id) {
                $user = User::find($model->user_id);
            } elseif ($model->portfolio_id) {
                $portfolio = BasePortfolio::find($model->portfolio_id);
                if ($portfolio) {
                    $user = $portfolio->owners()->first();
                }
            }

            if ($user) {
                $subscription = UserSubscription::where('user_id', $user->id)
                    ->whereIn('status', ['active', 'trial'])
                    ->first();

                if (!$subscription) {
                    throw new \Exception('Active subscription required to add trades');
                }

                // Check if trial is expired
                if ($subscription->isTrial() && $subscription->isTrialExpired()) {
                    throw new \Exception('Trial subscription has expired. Please upgrade to continue adding trades');
                }

                // Check if subscription is expired
                if ($subscription->isActive() && $subscription->isSubscriptionExpired()) {
                    throw new \Exception('Subscription has expired. Please renew to continue adding trades');
                }
            }
        });
    }

    /**
     * Get the user that owns the trade (can be null, will get from portfolio)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the portfolio that owns the trade
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    /**
     * Get the stock for this trade
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Get buy lots created from this trade (for buy trades)
     */
    public function buyLots(): HasMany
    {
        return $this->hasMany(BuyLot::class);
    }

    /**
     * Get completed trades from this trade (for sell trades)
     */
    public function completedTrades(): HasMany
    {
        return $this->hasMany(CompletedTrade::class, 'sell_trade_id');
    }

    /**
     * Get deductions related to this trade
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class);
    }

    public function taxPayable()
    {
        return $this->hasMany(TaxPayable::class);
    }

    public function canBeDeleted()
    {
        if ($this->type === 'buy') {
            $usedInSellTrades = BuyLot::where('trade_id', $this->id)
                ->where(function($query) {
                    $query->whereColumn('remaining_quantity', '<', 'quantity')
                        ->orWhereHas('completedTrades');
                })->exists();

            if ($usedInSellTrades) {
                return false;
            }
        }

        if ($this->type === 'sell' && $this->completedTrades()->exists()) {
            return false;
        }

        $hasPaidTaxes = $this->taxPayable()
            ->where('status', 'paid')
            ->exists();

        if ($hasPaidTaxes) {
            return false;
        }

        $ageLimit = config('app.trade_deletion_age_limit', 30);
        if ($this->created_at->diffInDays(now()) > $ageLimit) {
            return false;
        }

        return true;
    }

    public function getDeleteRestrictionReason()
    {
        if ($this->type === 'buy') {
            $usedInSellTrades = BuyLot::where('trade_id', $this->id)
                ->where(function($query) {
                    $query->whereColumn('remaining_quantity', '<', 'quantity')
                        ->orWhereHas('completedTrades');
                })->exists();

            if ($usedInSellTrades) {
                return "This buy trade cannot be deleted because it has been partially or fully sold in subsequent sell trades.";
            }
        }

        if ($this->type === 'sell' && $this->completedTrades()->exists()) {
            return "This sell trade cannot be deleted because it has generated completed trade records.";
        }

        $hasPaidTaxes = $this->taxPayable()
            ->where('status', 'paid')
            ->exists();

        if ($hasPaidTaxes) {
            return "This trade cannot be deleted because it has tax records that have been marked as paid.";
        }

        $ageLimit = config('app.trade_deletion_age_limit', 30);
        if ($this->created_at->diffInDays(now()) > $ageLimit) {
            return "This trade cannot be deleted because it is more than {$ageLimit} days old.";
        }

        return "This trade cannot be deleted due to system restrictions.";
    }

    /**
     * Get the price per share
     */
    public function getPricePerShareAttribute()
    {
        if ($this->quantity > 0) {
            return $this->amount / $this->quantity;
        }
        return $this->price ?? 0;
    }

    /**
     * Compatibility method for amount field
     */
    public function getAmountAttribute($value)
    {
        return $value ?? $this->attributes['total_amount'] ?? 0;
    }

    /**
     * Compatibility method for date field
     */
    public function getDateAttribute($value)
    {
        return $value ?? $this->attributes['trade_date'] ?? null;
    }

    /**
     * Get the effective user (from user_id or portfolio owner)
     */
    public function getEffectiveUserAttribute()
    {
        if ($this->user_id && $this->user) {
            return $this->user;
        }

        if ($this->portfolio) {
            return $this->portfolio->owners()->first();
        }

        return null;
    }
}

