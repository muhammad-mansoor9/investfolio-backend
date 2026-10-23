<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Dashboard;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

trait HasDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function xTicks(): array
    {
        return ['maxRotation' => 0, 'autoSkip' => true, 'maxTicksLimit' => 8];
    }

    protected function periodDays(): int
    {
        $days = (int) ($this->filters['period'] ?? 30);

        return array_key_exists($days, Dashboard::PERIODS) ? $days : 30;
    }

    protected function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays($this->periodDays() - 1);
    }

    protected function previousPeriodStart(): CarbonImmutable
    {
        return $this->periodStart()->subDays($this->periodDays());
    }

    protected function periodLabel(): string
    {
        return strtolower(Dashboard::PERIODS[$this->periodDays()]);
    }

    protected function bucketUnit(): string
    {
        return $this->periodDays() > 31 ? 'week' : 'day';
    }

    protected function buckets(): Collection
    {
        $unit = $this->bucketUnit();
        $first = $unit === 'week' ? $this->periodStart()->startOfWeek(CarbonImmutable::MONDAY) : $this->periodStart();
        $buckets = collect();

        for ($day = $first; $day->lte(CarbonImmutable::today()); $day = $day->add(1, $unit)) {
            $buckets->push($day);
        }

        return $buckets;
    }

    protected function bucketLabels(): array
    {
        return $this->buckets()->map(fn (CarbonImmutable $day) => $day->format('j M'))->all();
    }

    // Postgres date_trunc('week') starts on Monday, matching buckets().
    protected function series(Builder $query, string $column = 'created_at'): array
    {
        $unit = $this->bucketUnit();
        $totals = (clone $query)
            ->reorder()
            ->where($column, '>=', $this->buckets()->first())
            ->selectRaw("date_trunc('{$unit}', {$column})::date AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->buckets()->map(fn (CarbonImmutable $day) => (float) ($totals[$day->toDateString()] ?? 0))->all();
    }

    protected function periodCounts(Builder $query, string $column = 'created_at'): array
    {
        return [
            (clone $query)->where($column, '>=', $this->periodStart())->count(),
            (clone $query)->where($column, '>=', $this->previousPeriodStart())->where($column, '<', $this->periodStart())->count(),
        ];
    }

    protected function trend(float $current, float $previous): array
    {
        if ($previous == 0.0) {
            return [$current > 0 ? 'New this period' : 'No change', 'heroicon-m-minus', 'gray'];
        }

        $change = (int) round(($current - $previous) / $previous * 100);

        return match (true) {
            $change > 0 => ["+{$change}% vs previous period", 'heroicon-m-arrow-trending-up', 'success'],
            $change < 0 => ["{$change}% vs previous period", 'heroicon-m-arrow-trending-down', 'danger'],
            default => ['Same as previous period', 'heroicon-m-minus', 'gray'],
        };
    }
}
