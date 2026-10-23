<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Models\JobHistory;
use App\Support\AdminModules;
use Filament\Widgets\ChartWidget;

class JobRunsChart extends ChartWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 4];

    protected static ?string $heading = 'Job runs';

    protected static ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::QUALITY);
    }

    public function getDescription(): ?string
    {
        return 'Successful and failed scraper runs, '.$this->periodLabel();
    }

    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'Successful',
                    'data' => $this->series(JobHistory::query()->where('status', 'success'), 'run_timestamp'),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.75)',
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Failed',
                    'data' => $this->series(JobHistory::query()->where('status', 'failed'), 'run_timestamp'),
                    'backgroundColor' => 'rgba(244, 63, 94, 0.8)',
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $this->bucketLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false], 'ticks' => $this->xTicks()],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
