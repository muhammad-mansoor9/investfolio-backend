<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Index;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\IndexPrice;
use App\Models\SectorStockScore;
use App\Services\MarketRegimeService;
use App\Services\SectorRotationUnifiedService;
use App\Services\StockUnifiedAnalysisService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketPulseDailyCommand extends Command
{
    protected $signature = 'market-pulse:daily
                            {--date= : Trading date (Y-m-d), default: yesterday}
                            {--benchmark=KSE100 : Benchmark symbol}';

    protected $description = 'Calculate market pulse analytics for a single trading date';

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
            $date = $this->parseDate();
            $benchmarkSymbol = $this->option('benchmark') ?? 'KSE100';
            $dateStr = $date->format('Y-m-d');

            $this->info("Calculating market pulse analytics for {$dateStr}");

            $benchmark = Index::where('symbol', $benchmarkSymbol)->first();
            if (!$benchmark) {
                $this->error("Benchmark index '{$benchmarkSymbol}' not found");
                return self::FAILURE;
            }

            if (!$this->hasDataForDate($dateStr, $benchmark->id)) {
                $this->warn("Market data not available for {$dateStr}, skipping");
                Log::warning("MarketPulseDailyCommand: Data not available", ['date' => $dateStr]);
                return self::SUCCESS;
            }

            DB::beginTransaction();

            try {
                // 1. Market regime calculations
                $this->info('Calculating market regime...');
                $regimeResult = $this->regimeService->calculateAndPersistDailyMetrics(
                    $benchmark->id,
                    new \DateTime($dateStr)
                );

                if (!$regimeResult) {
                    $this->warn('Market regime calculation returned no data');
                } else {
                    $this->info("Market regime: {$regimeResult['regime']} (score: {$regimeResult['regime_score']})");
                }

                // 2-15. Sector and stock analyses
                $this->info('Calculating sector rotation metrics...');
                $this->sectorRotationService->calculateAndPersistDailyMetrics($benchmark->id, $dateStr);
                $this->info('Sector rotation metrics complete');

                // 16-17. Stock analyses
                $this->info('Calculating stock analysis metrics...');
                $stocksProcessed = 0;
                $stocksErrors = 0;

                $stocks = Stock::where('is_active', true)->get();
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

                $this->info("Stock analysis complete: {$stocksProcessed} processed, {$stocksErrors} errors");

                DB::commit();

                $this->newLine();
                $this->info('Market pulse calculation completed successfully');
                $this->table(['Component', 'Status'], [
                    ['Market Regime', 'Calculated'],
                    ['Sector Rotation', 'Calculated'],
                    ['Stock Analysis', "{$stocksProcessed} stocks calculated"],
                ]);

                return self::SUCCESS;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            $this->error("Daily calculation failed: {$e->getMessage()}");
            Log::error('MarketPulseDailyCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    private function hasDataForDate(string $dateStr, string $benchmarkId): bool
    {
        return IndexPrice::where('index_id', $benchmarkId)
            ->where('date', $dateStr)
            ->exists() &&
            StockPrice::where('date', $dateStr)
            ->exists();
    }

    private function parseDate(): Carbon
    {
        if ($this->option('date')) {
            return Carbon::createFromFormat('Y-m-d', $this->option('date'));
        }
        return now()->subDay()->startOfDay();
    }
}
