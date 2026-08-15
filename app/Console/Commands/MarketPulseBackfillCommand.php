<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Index;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\IndexPrice;
use App\Models\StockPrice;
use App\Models\MarketRegimeMetric;
use App\Models\SectorRotationMetric;
use App\Models\SectorStockScore;
use App\Services\MarketRegimeService;
use App\Services\SectorRotationUnifiedService;
use App\Services\StockUnifiedAnalysisService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketPulseBackfillCommand extends Command
{
    protected $signature = 'market-pulse:backfill
                            {--from-date= : Start date (Y-m-d), default: 1 year ago}
                            {--to-date= : End date (Y-m-d), default: yesterday}
                            {--benchmark=KSE100 : Benchmark symbol}';

    protected $description = 'Backfill market pulse analytics for a date range';

    public function __construct(
        private MarketRegimeService $regimeService,
        private SectorRotationUnifiedService $sectorRotationService,
        private StockUnifiedAnalysisService $stockAnalysisService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $fromDate = $this->parseFromDate();
            $toDate = $this->parseToDate();
            $benchmarkSymbol = $this->option('benchmark') ?? 'KSE100';

            if ($fromDate > $toDate) {
                $this->error('From date must be before to date');
                return self::FAILURE;
            }

            $benchmark = Index::where('symbol', $benchmarkSymbol)->first();
            if (!$benchmark) {
                $this->error("Benchmark index '{$benchmarkSymbol}' not found");
                return self::FAILURE;
            }

            $this->info("Backfilling market pulse analytics");
            $this->info("Date range: {$fromDate->format('Y-m-d')} to {$toDate->format('Y-m-d')}");
            $this->info("Benchmark: {$benchmarkSymbol}");
            $this->newLine();

            $datesProcessed = 0;
            $recordsCreated = 0;
            $errors = 0;

            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dateStr = $current->format('Y-m-d');

                try {
                    $this->processDate($dateStr, $benchmark->id, $recordsCreated, $errors);
                    $datesProcessed++;

                    if ($datesProcessed % 50 === 0) {
                        $this->info("Processed {$datesProcessed} dates, {$recordsCreated} records created");
                    }
                } catch (\Exception $e) {
                    $this->warn("Error processing date {$dateStr}: {$e->getMessage()}");
                    $errors++;
                }

                $current->addDay();
            }

            $this->newLine();
            $this->info('Backfill completed');
            $this->table(['Metric', 'Value'], [
                ['Dates processed', $datesProcessed],
                ['Records created', $recordsCreated],
                ['Errors', $errors],
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Backfill failed: {$e->getMessage()}");
            Log::error('MarketPulseBackfillCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    private function processDate(string $dateStr, string $benchmarkId, int &$recordsCreated, int &$errors): void
    {
        $countBefore = [
            'metrics' => MarketRegimeMetric::where('date', $dateStr)->count(),
            'sectors' => SectorRotationMetric::where('date', $dateStr)->count(),
            'stocks' => SectorStockScore::where('date', $dateStr)->count(),
        ];

        // 1. Market regime calculations
        try {
            $this->regimeService->calculateAndPersistDailyMetrics($benchmarkId, new \DateTime($dateStr));
        } catch (\Exception $e) {
            Log::warning("Market regime calculation failed for {$dateStr}: {$e->getMessage()}");
            $errors++;
        }

        // 2-15. Sector and stock analyses
        try {
            $this->sectorRotationService->calculateAndPersistDailyMetrics($benchmarkId, $dateStr);
        } catch (\Exception $e) {
            Log::warning("Sector rotation calculation failed for {$dateStr}: {$e->getMessage()}");
            $errors++;
        }

        // 16-17. Stock analyses (iterate all stocks)
        try {
            $stocks = Stock::whereRaw('is_active = true')->get();
            foreach ($stocks as $stock) {
                try {
                    $result = $this->stockAnalysisService->analyzeStock($stock->id, $dateStr, $stock->sector_id);
                    if (!$result || isset($result['metadata']['error'])) {
                        continue;
                    }

                    SectorStockScore::updateOrCreate(
                        [
                            'stock_id' => $stock->id,
                            'date' => $dateStr,
                        ],
                        [
                            'sector_id' => $stock->sector_id,
                            'relative_leadership_score' => $result['relative_leadership_score'] ?? 0,
                            'trend_structure_score' => $result['trend_structure_score'] ?? 0,
                            'momentum_score' => $result['momentum_score'] ?? 0,
                            'participation_score' => $result['participation_score'] ?? 0,
                            'stock_strength_score' => $result['stock_strength_score'] ?? 0,
                            'watch_score' => $result['watch_score'] ?? 0,
                            'simple_state' => $result['simple_state'] ?? 'Weak',
                            'metadata' => $result['metadata'] ?? [],
                        ]
                    );
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning("Error analyzing stock {$stock->symbol} on {$dateStr}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Stock analysis failed for {$dateStr}: {$e->getMessage()}");
            $errors++;
        }

        $countAfter = [
            'metrics' => MarketRegimeMetric::where('date', $dateStr)->count(),
            'sectors' => SectorRotationMetric::where('date', $dateStr)->count(),
            'stocks' => SectorStockScore::where('date', $dateStr)->count(),
        ];

        $recordsCreated += ($countAfter['metrics'] - $countBefore['metrics']) +
                           ($countAfter['sectors'] - $countBefore['sectors']) +
                           ($countAfter['stocks'] - $countBefore['stocks']);
    }

    private function parseFromDate(): Carbon
    {
        if ($this->option('from-date')) {
            return Carbon::createFromFormat('Y-m-d', $this->option('from-date'));
        }
        return now()->subYear()->startOfDay();
    }

    private function parseToDate(): Carbon
    {
        if ($this->option('to-date')) {
            return Carbon::createFromFormat('Y-m-d', $this->option('to-date'));
        }
        return now()->subDay()->endOfDay();
    }
}
