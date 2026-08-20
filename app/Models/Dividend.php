<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\HasUuid;

class Dividend extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'user_id', 'portfolio_id', 'stock_id', 'announcement_id', 'type',
        'amount', 'quantity', 'per_share_amount', 'dividend_percentage',
        'date', 'description', 'status'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'integer',
        'per_share_amount' => 'decimal:2',
        'dividend_percentage' => 'decimal:4',
        'date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string)Str::uuid();
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
        });
    }

    /**
     * Get the user that owns the dividend (can be null, will get from portfolio)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the portfolio that owns the dividend
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    /**
     * Get the stock for this dividend
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Get the announcement for this dividend
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * Get deductions related to this dividend (taxes)
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
        $hasPaidTaxes = $this->taxPayable()
            ->where('status', 'paid')
            ->exists();

        if ($hasPaidTaxes) {
            return false;
        }

        $ageLimit = config('app.dividend_deletion_age_limit', 30);
        if ($this->created_at->diffInDays(now()) > $ageLimit) {
            return false;
        }

        return true;
    }

    public function getDeleteRestrictionReason()
    {
        $hasPaidTaxes = $this->taxPayable()
            ->where('status', 'paid')
            ->exists();

        if ($hasPaidTaxes) {
            return "This dividend cannot be deleted because it has tax records that have been marked as paid.";
        }

        $ageLimit = config('app.dividend_deletion_age_limit', 30);
        if ($this->created_at->diffInDays(now()) > $ageLimit) {
            return "This dividend cannot be deleted because it is more than {$ageLimit} days old.";
        }

        return "This dividend cannot be deleted due to system restrictions.";
    }

    /**
     * Get the net amount after taxes
     */
    public function getNetAmountAttribute()
    {
        $taxes = $this->deductions()->where('type', 'dividend_tax')->sum('amount');
        return $this->amount - $taxes;
    }

    /**
     * Get the tax amount
     */
    public function getTaxAmountAttribute()
    {
        return $this->deductions()->where('type', 'dividend_tax')->sum('amount');
    }

    /**
     * Get formatted dividend type
     */
    public function getFormattedTypeAttribute()
    {
        $typeMap = [
            'cash_dividend' => 'Cash Dividend',
            'bonus_shares' => 'Bonus Shares',
            'rights_shares' => 'Rights Shares',
            'merger_shares' => 'Merger Shares',
            'split' => 'Stock Split',
        ];

        return $typeMap[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
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

    /**
     * Get formatted dividend percentage for display
     */
    public function getFormattedDividendPercentageAttribute()
    {
        if (!$this->dividend_percentage) {
            return '0%';
        }

        if ($this->type === 'cash_dividend') {
            return number_format($this->dividend_percentage, 2) . '%';
        } elseif ($this->type === 'bonus_shares') {
            return number_format($this->dividend_percentage, 2) . '% bonus';
        } elseif ($this->type === 'split') {
            return number_format($this->dividend_percentage, 2) . '% split';
        }

        return number_format($this->dividend_percentage, 2) . '%';
    }

    /**
     * Calculate expected bonus shares (before tax)
     */
    public function getExpectedBonusSharesAttribute()
    {
        if (($this->type === 'bonus_shares' || $this->type === 'split') && $this->dividend_percentage && $this->quantity) {
            return floor($this->quantity * ($this->dividend_percentage / 100));
        }
        return 0;
    }

    /**
     * Check if this dividend type has tax implications
     */
    public function getHasTaxAttribute()
    {
        return !in_array($this->type, ['split']);
    }
}

