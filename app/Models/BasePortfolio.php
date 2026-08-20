<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Investfolio\InvestfolioShared\Services\PortfolioService;
use Investfolio\InvestfolioShared\Services\TaxService;
use App\Traits\HasUuid;

class BasePortfolio extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $table = 'portfolios';

    protected $fillable = [
        'name',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [];

    protected $with = ['users', 'trades'];

    /**
     * Share a portfolio with a user
     */
    public function share(string $email, bool $fullAccess = false): void
    {
        $user = User::firstOrCreate([
            'email' => $email,
        ], [
            'name' => Str::title(Str::before($email, '@')),
        ]);

        $permissions[$user->id] = [
            'full_access' => $fullAccess,
            'is_owner' => 0, // Not owner when sharing
        ];

        $sync = $this->users()->syncWithoutDetaching($permissions);

        if (!empty($sync['attached'])) {
            foreach ($sync['attached'] as $newUserId) {
                // User::find($newUserId)->notify(new InvitedOnboardingNotification($this, auth()->user()));
            }
        }
    }

    /**
     * Un-share a portfolio
     */
    public function unShare(string $userId): void
    {
        $this->users()->detach($userId);
    }

    /**
     * Get all trades for this portfolio
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'portfolio_id')->orderBy('date', 'DESC');
    }

    /**
     * Get buy lots for this portfolio
     */
    public function buyLots(): HasMany
    {
        return $this->hasMany(BuyLot::class, 'portfolio_id');
    }

    /**
     * Get completed trades for this portfolio
     */
    public function completedTrades(): HasMany
    {
        return $this->hasMany(CompletedTrade::class, 'portfolio_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'portfolio_user', 'portfolio_id', 'user_id')
            ->withPivot(['is_owner', 'full_access', 'invite_accepted_at']);
    }

    /** BasePortfolio owner (pivot `is_owner` = 1) */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('is_owner', 1);
    }

    /** Non-owner collaborators */
    public function collaborators(): BelongsToMany
    {
        return $this->users()->wherePivot('is_owner', 0);
    }

    /**
     * Get the dividends for the portfolio.
     */
    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class, 'portfolio_id');
    }

    /**
     * Get the deductions for the portfolio.
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class, 'portfolio_id');
    }

    /**
     * Get the deduction slab for the portfolio.
     */
    public function deductionSlab(): BelongsTo
    {
        return $this->belongsTo(DeductionSlab::class);
    }

    public function getMetrics(): array
    {
        $portfolioService = new PortfolioService();
        $taxService = new TaxService();

        // Get current holdings from BuyLots with remaining quantity > 0
        $holdings = $this->getCurrentHoldings();

        // Calculate investment amount from current holdings
        $investmentAmount = $holdings->sum('total_invested');

        // Calculate current market value using StockPrice table
        $marketValue = $holdings->sum(function ($holding) {
            $latestPrice = StockPrice::where('stock_id', $holding->stock_id)
                ->orderBy('date', 'desc')
                ->first();

            return $holding->total_quantity * ($latestPrice?->close ?? 0);
        });

        // Calculate today's return using StockPrice table
        $todaysReturn = $holdings->sum(function ($holding) {
            $latestPrice = StockPrice::where('stock_id', $holding->stock_id)
                ->orderBy('date', 'desc')
                ->first();

            return $holding->total_quantity * ($latestPrice?->change ?? 0);
        });

        // Unrealized profit from current holdings
        $unrealizedProfit = $marketValue - $investmentAmount;

        // Realized profit from completed trades
        $realizedProfit = $this->completedTrades()->sum('profit');

        // Total return = unrealized + realized
        $totalReturn = $unrealizedProfit + $realizedProfit;

        // Get total investment including sold positions
        $totalInvestment = $this->buyLots()
            ->selectRaw('SUM(quantity * price) as total')
            ->value('total') ?? 0;

        $payouts = $this->dividends()->sum('amount');
        $deductions = $this->deductions()->sum('amount');
        $taxPayable = $taxService->getPortfolioTaxSummary($this->id)
            ->where('tax_type', 'pending')
            ->sum('pending_amount');

        return [
            'investment_amount' => $investmentAmount,
            'unrealized_profit' => $unrealizedProfit,
            'realized_profit' => $realizedProfit,
            'payouts' => $payouts,
            'todays_return' => $todaysReturn,
            'todays_return_percent' => $investmentAmount > 0 ? ($todaysReturn / $investmentAmount) * 100 : 0,
            'total_return' => $totalReturn,
            'total_return_percent' => $totalInvestment > 0 ? ($totalReturn / $totalInvestment) * 100 : 0,
            'market_value' => $marketValue,
            'deductions' => $deductions,
            'tax_payable' => $taxPayable,
            'total_investment' => $totalInvestment,
        ];
    }

    public function getCurrentHoldings()
    {
        $buyLots = BuyLot::where('portfolio_id', $this->id)
            ->where('remaining_quantity', '>', 0)
            ->with(['stock.sector']) // Remove latestPrice since we'll get it from StockPrice table
            ->get();

        return $buyLots->groupBy('stock_id')->map(function ($lots) {
            $firstLot = $lots->first();
            $totalQuantity = $lots->sum('remaining_quantity');
            $totalInvested = $lots->sum(function ($lot) {
                return $lot->remaining_quantity * $lot->price;
            });

            return (object)[
                'stock_id' => $firstLot->stock_id,
                'symbol' => $firstLot->stock->symbol,
                'total_quantity' => $totalQuantity,
                'avg_price' => $totalQuantity > 0 ? $totalInvested / $totalQuantity : 0,
                'total_invested' => $totalInvested,
                'stock' => $firstLot->stock, // Include the stock relationship
            ];
        })->values();
    }
}

