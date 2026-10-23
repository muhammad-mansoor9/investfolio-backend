<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DailyIntegrity;
use App\Services\DataIntegrity\DailyDataIntegrityService;
use App\Support\AdminModules;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DataFreshness extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Market data freshness';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::QUALITY);
    }

    protected function getDescription(): ?string
    {
        $latest = $this->report()['latestTradingDate'];

        return $latest ? 'Latest trading day '.Carbon::parse($latest)->format('D j M Y') : null;
    }

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getStats(): array
    {
        $report = $this->report();

        return collect(DailyIntegrity::DATASETS)->map(function (string $label, string $key) use ($report) {
            $overview = $report['datasets'][$key];

            return Stat::make($label, $overview['latestDate'] ? Carbon::parse($overview['latestDate'])->format('j M Y') : 'No data')
                ->icon($overview['upToDate'] ? 'heroicon-o-check-circle' : 'heroicon-o-clock')
                ->description(match (true) {
                    ! $overview['upToDate'] => 'Behind the latest trading day',
                    $overview['missingDays'] > 0 => $overview['missingDays'].' older days never loaded',
                    default => 'Current, no gaps',
                })
                ->descriptionIcon($overview['upToDate'] && $overview['missingDays'] === 0 ? 'heroicon-m-check' : 'heroicon-m-exclamation-triangle')
                ->color(match (true) {
                    ! $overview['upToDate'] => 'danger',
                    $overview['missingDays'] > 0 => 'warning',
                    default => 'success',
                })
                ->url(DailyIntegrity::getUrl());
        })->values()->all();
    }

    private function report(): array
    {
        return app(DailyDataIntegrityService::class)->getMonth(now('Asia/Karachi')->format('Y-m'));
    }
}
