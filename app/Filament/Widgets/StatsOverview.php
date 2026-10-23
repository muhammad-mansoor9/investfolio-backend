<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\JobRunResource;
use App\Filament\Resources\StockResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserSubscriptionResource;
use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Models\JobHistory;
use App\Models\Stock;
use App\Models\User;
use App\Models\UserSubscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class StatsOverview extends StatsOverviewWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $users = User::query();
        [$signups, $signupsBefore] = $this->periodCounts(clone $users);
        $totalUsers = (clone $users)->count();

        $paid = UserSubscription::query()
            ->whereHas('plan', fn ($plan) => $plan->where('is_default', false))
            ->where('status', '!=', 'paused')
            ->count();

        $activeStocks = Stock::where('is_active', true)->count();
        $shariah = Stock::where('is_active', true)->where('is_shariah', true)->count();

        $runs = JobHistory::query()->whereIn('status', ['success', 'failed']);
        $finished = (clone $runs)->where('run_timestamp', '>=', $this->periodStart())->count();
        $failed = (clone $runs)->where('status', 'failed')->where('run_timestamp', '>=', $this->periodStart())->count();
        $failedBefore = (clone $runs)->where('status', 'failed')
            ->where('run_timestamp', '>=', $this->previousPeriodStart())->where('run_timestamp', '<', $this->periodStart())->count();

        return [
            $this->withTrend(Stat::make('New users', Number::format($signups)), $signups, $signupsBefore)
                ->icon('heroicon-o-user-plus')
                ->chart($this->series(clone $users))
                ->url(UserResource::getUrl()),
            Stat::make('Paying subscribers', Number::format($paid))
                ->icon('heroicon-o-star')
                ->description($totalUsers > 0 ? round($paid / $totalUsers * 100, 1).'% of '.Number::format($totalUsers).' users' : 'No users yet')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(UserSubscriptionResource::getUrl()),
            Stat::make('Active stocks', Number::format($activeStocks))
                ->icon('heroicon-o-building-library')
                ->description(Number::format($shariah).' Shariah compliant')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info')
                ->url(StockResource::getUrl()),
            Stat::make('Job success rate', $finished > 0 ? round(($finished - $failed) / $finished * 100, 1).'%' : '—')
                ->icon('heroicon-o-cpu-chip')
                ->description(Number::format($failed).' failed runs, '.$this->periodLabel())
                ->descriptionIcon($failed > $failedBefore ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-check-circle')
                ->color($finished === 0 ? 'gray' : ($failed / $finished > 0.1 ? 'danger' : ($failed > 0 ? 'warning' : 'success')))
                ->chart($this->series(JobHistory::query()->where('status', 'failed'), 'run_timestamp'))
                ->url(JobRunResource::getUrl()),
        ];
    }

    private function withTrend(Stat $stat, float $current, float $previous): Stat
    {
        [$description, $icon, $color] = $this->trend($current, $previous);

        return $stat->description($description)->descriptionIcon($icon)->color($color);
    }
}
