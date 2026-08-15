<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Index;
use App\Models\Stock;
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

class MarketPulseRebuildCommand extends Command
{
    protected $signature = 'market-pulse:rebuild
                            {component : Component to rebuild (market-regime, sector-rotation, stock-analysis, all)}
                            {--from-date= : Start date (Y-m-d), default: 1 year ago}
                            {--to-date= : End date (Y-m-d), default: yesterday}
                            {--benchmark=KSE100 : Benchmark symbol}
                            {--force : Skip confirmation}';

    protected $description = 'Rebuild specific market pulse components for a date range';

    private const VALID_COMPONENTS = ['market-regime', 'sector-rotation', 'stock-analysis', 'all'];

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
            $component = $this->argument('component');

            if (!in_array($component, self::VALID_COMPONENTS)) {
                $this->error("Invalid component: {$component}");
                $this->info('Valid components: ' . implode(', ', self::VALID_COMPONENTS));
                return self::FAILURE;
            }

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

            $this->info("Rebuilding component: {$component}");
            $this->info("Date range: {$fromDate->format('Y-m-d')} to {$toDate->format('Y-m-d')}");
            $this->info("Benchmark: {$benchmarkSymbol}");

            if (!$this->option('force')) {
                if (!$this->confirm('This will delete and recalculate existing data. Continue?')) {
                    $this->info('Rebuild cancelled');
                    return self::SUCCESS;
                }
            }

            $this->newLine();

            return match ($component) {
                'market-regime' => $this->rebuildMarketRegime($fromDate, $toDate, $benchmark->id),
                'sector-rotation' => $this->rebuildSectorRotation($fromDate, $toDate, $benchmark->id),
                'stock-analysis' => $this->rebuildStockAnalysis($fromDate, $toDate, $benchmark->id),
                'all' => $this->rebuildAll($fromDate, $toDate, $benchmark->id),
            };
        } catch (\Exception $e) {
            $this->error("Rebuild failed: {$e->getMessage()}");
            Log::error('MarketPulseRebuildCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    private function rebuildMarketRegime(Carbon $fromDate, Carbon $toDate, string $benchmarkId): int
    {
        try {
            $this->info('Deleting existing market regime metrics...');
            $deleted = MarketRegimeMetric::where('index_id', $benchmarkId)
                ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
                ->delete();
            $this->info("Deleted {$deleted} records");

            $this->info('Recalculating market regime metrics...');
            $datesProcessed = 0;
            $recordsCreated = 0;

            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dateStr = $current->format('Y-m-d');

                try {
                    $result = $this->regimeService->calculateAndPersistDailyMetrics(
                        $benchmarkId,
                        new \DateTime($dateStr)
                    );

                    if ($result) {
                        $recordsCreated++;
                    }

                    $datesProcessed++;

                    if ($datesProcessed % 50 === 0) {
                        $this->info("Processed {$datesProcessed} dates, {$recordsCreated} records created");
                    }
                } catch (\Exception $e) {
                    $this->warn("Error processing date {$dateStr}: {$e->getMessage()}");
                }

                $current->addDay();
            }

            $this->newLine();
            $this->info('Market regime rebuild completed');
            $this->table(['Metric', 'Value'], [
                ['Dates processed', $datesProcessed],
                ['Records created', $recordsCreated],
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Market regime rebuild failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function rebuildSectorRotation(Carbon $fromDate, Carbon $toDate, string $benchmarkId): int
    {
        try {
            $this->info('Deleting existing sector rotation metrics and periods...');
            $deletedMetrics = SectorRotationMetric::where('benchmark_index_id', $benchmarkId)
                ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
                ->delete();
            $this->info("Deleted {$deletedMetrics} rotation metrics");

            $this->info('Recalculating sector rotation metrics...');
            $datesProcessed = 0;

            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dateStr = $current->format('Y-m-d');

                try {
                    $this->sectorRotationService->calculateAndPersistDailyMetrics($benchmarkId, $dateStr);
                    $datesProcessed++;

                    if ($datesProcessed % 50 === 0) {
                        $this->info("Processed {$datesProcessed} dates");
                    }
                } catch (\Exception $e) {
                    $this->warn("Error processing date {$dateStr}: {$e->getMessage()}");
                }

                $current->addDay();
            }

            $this->newLine();
            $this->info('Sector rotation rebuild completed');
            $this->table(['Metric', 'Value'], [
                ['Dates processed', $datesProcessed],
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sector rotation rebuild failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function rebuildStockAnalysis(Carbon $fromDate, Carbon $toDate, string $benchmarkId): int
    {
        try {
            $this->info('Deleting existing stock analysis scores...');
            $deleted = SectorStockScore::whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
                ->delete();
            $this->info("Deleted {$deleted} records");

            $this->info('Recalculating stock analysis metrics...');
            $datesProcessed = 0;
            $stocksProcessed = 0;
            $stocksErrors = 0;

            $stocks = Stock::where('is_active', true)->get();
            $stockCount = $stocks->count();

            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dateStr = $current->format('Y-m-d');
                $datesProcessed++;

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
                                'relative_leadership_score' => $result['relative_leadership_score'],
                                'trend_structure_score' => $result['trend_structure_score'],
                                'momentum_score' => $result['momentum_score'],
                                'participation_score' => $result['participation_score'],
                                'stock_strength_score' => $result['stock_strength_score'],
                                'watch_score' => $result['watch_score'],
                                'simple_state' => $result['simple_state'],
                                'metadata' => $result['metadata'],
                            ]
                        );
                        $stocksProcessed++;
                    } catch (\Exception $e) {
                        $stocksErrors++;
                        Log::warning("Error analyzing stock {$stock->symbol} on {$dateStr}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($datesProcessed % 10 === 0) {
                    $this->info("Processed {$datesProcessed} dates, {$stocksProcessed} stocks analyzed");
                }

                $current->addDay();
            }

            $this->newLine();
            $this->info('Stock analysis rebuild completed');
            $this->table(['Metric', 'Value'], [
                ['Dates processed', $datesProcessed],
                ['Stocks processed', $stocksProcessed],
                ['Errors', $stocksErrors],
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Stock analysis rebuild failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function rebuildAll(Carbon $fromDate, Carbon $toDate, string $benchmarkId): int
    {
        $components = ['market-regime', 'sector-rotation', 'stock-analysis'];
        $results = [];

        foreach ($components as $component) {
            $this->info("\n=== Rebuilding {$component} ===\n");

            $result = match ($component) {
                'market-regime' => $this->rebuildMarketRegime($fromDate, $toDate, $benchmarkId),
                'sector-rotation' => $this->rebuildSectorRotation($fromDate, $toDate, $benchmarkId),
                'stock-analysis' => $this->rebuildStockAnalysis($fromDate, $toDate, $benchmarkId),
            };

            $results[$component] = $result === self::SUCCESS ? 'Success' : 'Failed';
        }

        $this->newLine();
        $this->info('All components rebuild completed');
        $this->table(['Component', 'Status'], array_map(function ($k, $v) {
            return [$k, $v];
        }, array_keys($results), array_values($results)));

        return array_values($results) === array_fill(0, count($results), 'Success') ? self::SUCCESS : self::FAILURE;
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
