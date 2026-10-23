<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DailyIntegrity;
use App\Filament\Pages\FinancialIntegrity;
use App\Filament\Resources\JobRunResource;
use App\Filament\Resources\RescrapeQueueResource;
use App\Filament\Resources\UserResource;
use App\Models\JobHistory;
use App\Models\StockRescrapeQueue;
use App\Support\AdminModules;
use Filament\Widgets\Widget;

class WelcomeBanner extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.welcome-banner';

    protected function getViewData(): array
    {
        $hour = (int) now('Asia/Karachi')->format('G');
        $quality = AdminModules::allows(AdminModules::QUALITY);

        return [
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'),
            'name' => strtok((string) auth('admin')->user()?->name, ' ') ?: null,
            'shortcuts' => array_values(array_filter([
                $quality ? ['label' => 'Daily coverage', 'icon' => 'heroicon-o-calendar-days', 'url' => DailyIntegrity::getUrl(), 'badge' => 0] : null,
                $quality ? ['label' => 'Financial coverage', 'icon' => 'heroicon-o-document-chart-bar', 'url' => FinancialIntegrity::getUrl(), 'badge' => 0] : null,
                $quality ? ['label' => 'Failed jobs', 'icon' => 'heroicon-o-exclamation-triangle', 'url' => JobRunResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'failed']]]),
                    'badge' => JobHistory::where('status', 'failed')->where('run_timestamp', '>=', now()->subDay())->count()] : null,
                $quality ? ['label' => 'Rescrape queue', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'url' => RescrapeQueueResource::getUrl(),
                    'badge' => StockRescrapeQueue::where('status', 'pending')->count()] : null,
                AdminModules::allows(AdminModules::CUSTOMERS) ? ['label' => 'Users', 'icon' => 'heroicon-o-users', 'url' => UserResource::getUrl(), 'badge' => 0] : null,
            ])),
            'links' => [
                ['label' => 'Open the web app', 'icon' => 'heroicon-o-arrow-top-right-on-square', 'url' => config('app.frontend_url')],
                ['label' => 'Server logs', 'icon' => 'heroicon-o-document-text', 'url' => url('/log-viewer')],
            ],
        ];
    }
}
