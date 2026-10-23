<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Services\DataIntegrity\DailyDataIntegrityService;
use App\Support\AdminModules;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class DailyIntegrity extends Page
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::QUALITY;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Data quality';

    protected static ?string $navigationLabel = 'Daily coverage';

    protected static ?string $title = 'Daily market data coverage';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.daily-integrity';

    public const DATASETS = [
        'prices' => 'Stock prices',
        'indicators' => 'Indicators',
        'fipiTrading' => 'FIPI/LIPI sectors',
        'fipiMarket' => 'FIPI/LIPI markets',
        'uin' => 'UIN settlement',
    ];

    #[Url]
    public string $month = '';

    #[Url(as: 'issues')]
    public bool $issuesOnly = false;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > $this->currentMonth()) {
            $this->month = $this->currentMonth();
        }
    }

    public function getSubheading(): ?string
    {
        return 'Trading days are the dates the exchange produced stock or index prices. Partial means some priced stocks have no row that day.';
    }

    #[Computed]
    public function report(): array
    {
        return app(DailyDataIntegrityService::class)->getMonth($this->month);
    }

    #[Computed]
    public function rows(): array
    {
        $days = $this->report['days'];

        return $this->issuesOnly ? array_values(array_filter($days, fn ($day) => $day['status'] === 'issues')) : $days;
    }

    public function shiftMonth(int $delta): void
    {
        $month = Carbon::createFromFormat('Y-m-d', "{$this->month}-01")->addMonthsNoOverflow($delta)->format('Y-m');
        $this->month = min($month, $this->currentMonth());
        unset($this->report, $this->rows);
    }

    public function updatedIssuesOnly(): void
    {
        unset($this->rows);
    }

    public function monthLabel(): string
    {
        return Carbon::createFromFormat('Y-m-d', "{$this->month}-01")->format('F Y');
    }

    public function isCurrentMonth(): bool
    {
        return $this->month >= $this->currentMonth();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Recheck now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    Cache::forget("data_integrity:daily:{$this->month}");
                    Cache::forget('data_integrity:daily:overview');
                    unset($this->report, $this->rows);
                    Notification::make()->title('Coverage rechecked against the database')->success()->send();
                }),
        ];
    }

    private function currentMonth(): string
    {
        return Carbon::now('Asia/Karachi')->format('Y-m');
    }
}
