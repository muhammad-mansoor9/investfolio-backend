<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Models\StockRescrapeQueue;
use App\Services\DataIntegrity\FinancialDataIntegrityService;
use App\Support\AdminModules;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialIntegrity extends Page
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::QUALITY;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Data quality';

    protected static ?string $navigationLabel = 'Financial coverage';

    protected static ?string $title = 'Quarterly financials coverage';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.financial-integrity';

    public const TIMELINE_QUARTERS = 16;

    public const SOURCES = [
        FinancialDataIntegrityService::SOURCE_FINANCIAL_DATA => [
            'label' => 'Financial statements',
            'description' => 'Quarterly statement rows per stock (financial_data).',
            'rows' => 'statement rows',
        ],
        FinancialDataIntegrityService::SOURCE_FINANCIAL_RESULTS => [
            'label' => 'Quarterly results',
            'description' => 'Announced quarterly results per stock (financial_results).',
            'rows' => 'result rows',
        ],
    ];

    public const STATUSES = [
        'GAPS' => ['label' => 'Gaps in series', 'color' => 'danger'],
        'OVERDUE' => ['label' => 'Latest overdue', 'color' => 'warning'],
        'THIN' => ['label' => 'Thin periods', 'color' => 'warning'],
        'NO_DATA' => ['label' => 'No data', 'color' => 'gray'],
        'COMPLETE' => ['label' => 'Complete', 'color' => 'success'],
    ];

    public const PERIODS = [
        'available' => 'Available',
        'thin' => 'Thin (well below usual row count)',
        'gap' => 'Missing inside the series',
        'overdue' => 'Overdue (filing deadline passed)',
        'pending' => 'Not due yet',
    ];

    private const QUARTER_MONTHS = ['Mar', 'Jun', 'Sep', 'Dec'];

    #[Url]
    public string $source = FinancialDataIntegrityService::SOURCE_FINANCIAL_DATA;

    #[Url]
    public string $filter = 'attention';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        if (! array_key_exists($this->source, self::SOURCES)) {
            $this->source = FinancialDataIntegrityService::SOURCE_FINANCIAL_DATA;
        }
    }

    public function getSubheading(): ?string
    {
        return 'PSX filing deadlines: 30 days after Q1/Q3, 60 after the half-year, 120 after the year end, plus 15 days grace for scraping.';
    }

    #[Computed]
    public function report(): array
    {
        return app(FinancialDataIntegrityService::class)->getReport($this->source);
    }

    #[Computed]
    public function rows(): array
    {
        $term = strtoupper(trim($this->search));
        $severity = array_flip(array_keys(self::STATUSES));

        $rows = array_filter($this->report['stocks'], fn (array $stock) => $this->matchesFilter($stock)
            && ($term === '' || str_contains($stock['symbol'], $term)));

        usort($rows, fn ($a, $b) => [$severity[$a['status']], $a['symbol']] <=> [$severity[$b['status']], $b['symbol']]);

        return $rows;
    }

    #[Computed]
    public function timeline(): array
    {
        [$month, $year] = explode(" '", $this->report['latestEndedQuarter']);
        $end = (2000 + (int) $year) * 4 + array_search($month, self::QUARTER_MONTHS, true);

        return array_map(
            fn (int $index) => sprintf("%s '%02d", self::QUARTER_MONTHS[$index % 4], intdiv($index, 4) % 100),
            range($end - self::TIMELINE_QUARTERS + 1, $end)
        );
    }

    public function setSource(string $source): void
    {
        if (array_key_exists($source, self::SOURCES)) {
            $this->source = $source;
            unset($this->report, $this->rows, $this->timeline);
        }
    }

    public function toggleFilter(string $filter): void
    {
        $this->filter = $this->filter === $filter ? 'attention' : $filter;
        unset($this->rows);
    }

    public function updatedFilter(): void
    {
        unset($this->rows);
    }

    public function updatedSearch(): void
    {
        unset($this->rows);
    }

    public function queueRescrape(string $stockId, string $symbol): void
    {
        $alreadyQueued = StockRescrapeQueue::where('stock_id', $stockId)->where('status', 'pending')->exists();

        if (! $alreadyQueued) {
            StockRescrapeQueue::create([
                'stock_id' => $stockId,
                'reason' => 'Admin: incomplete '.self::SOURCES[$this->source]['label'].' series',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
        }

        Notification::make()
            ->title($alreadyQueued ? "{$symbol} is already waiting to be rescraped" : "{$symbol} queued for rescrape")
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
            Action::make('recheck')
                ->label('Recheck now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    Cache::forget("data_integrity:financial:v2:{$this->source}");
                    unset($this->report, $this->rows, $this->timeline);
                    Notification::make()->title('Coverage rechecked against the database')->success()->send();
                }),
        ];
    }

    private function matchesFilter(array $stock): bool
    {
        return match ($this->filter) {
            'all' => true,
            'attention' => $stock['status'] !== 'COMPLETE' || ! empty($stock['anomalies']),
            'anomalies' => ! empty($stock['anomalies']),
            default => $stock['status'] === $this->filter,
        };
    }

    private function exportCsv(): StreamedResponse
    {
        $rows = $this->rows;

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['symbol', 'sector', 'status', 'first_period', 'latest_period', 'expected_latest', 'quarters_available', 'gaps', 'overdue', 'thin', 'label_issues']);
            foreach ($rows as $stock) {
                fputcsv($out, [
                    $stock['symbol'],
                    $stock['sector'] ?? '',
                    self::STATUSES[$stock['status']]['label'],
                    $stock['firstPeriod'] ?? '',
                    $stock['latestPeriod'] ?? '',
                    $stock['expectedLatest'],
                    $stock['available'],
                    implode('; ', $stock['gaps']),
                    implode('; ', $stock['overdue']),
                    implode('; ', $stock['thin']),
                    implode('; ', $stock['anomalies']),
                ]);
            }
            fclose($out);
        }, "{$this->source}-integrity-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
