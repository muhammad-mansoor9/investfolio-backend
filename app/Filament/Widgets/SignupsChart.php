<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Models\User;
use App\Support\AdminModules;
use Filament\Widgets\ChartWidget;

class SignupsChart extends ChartWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected static ?string $heading = 'Sign-ups';

    protected static ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::CUSTOMERS);
    }

    public function getDescription(): ?string
    {
        return 'New accounts, '.$this->periodLabel();
    }

    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'Email',
                    'data' => $this->series(User::query()->where(fn ($q) => $q->where('provider', 'email')->orWhereNull('provider'))),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                ],
                [
                    'label' => 'Google',
                    'data' => $this->series(User::query()->where('provider', 'google')),
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.12)',
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 0,
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
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false], 'ticks' => $this->xTicks()],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
