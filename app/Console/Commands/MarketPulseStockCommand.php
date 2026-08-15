<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\SectorStockScore;
use App\Services\StockUnifiedAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketPulseStockCommand extends Command
{
    protected $signature = 'market-pulse:stock
                          {stockId : Stock UUID to calculate}
                          {--from-date= : Start date (YYYY-MM-DD), default 1 year ago}
                          {--to-date= : End date (YYYY-MM-DD), default yesterday}
                          {--benchmark=KSE100 : Benchmark index symbol}';

    protected $description = 'Calculate market pulse analysis for a specific stock';

    public function __construct(
        private StockUnifiedAnalysisService $stockAnalysisService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $stockId = $this->argument('stockId');
            $fromDate = $this->option('from-date')
                ? Carbon::createFromFormat('Y-m-d', $this->option('from-date'))->toDateString()
                : Carbon::now()->subYear()->toDateString();
            $toDate = $this->option('to-date')
                ? Carbon::createFromFormat('Y-m-d', $this->option('to-date'))->toDateString()
                : Carbon::now()->subDay()->toDateString();
            $benchmark = $this->option('benchmark');

            // Validate stock exists
            $stock = Stock::findOrFail($stockId);
            $this->info("Calculating market pulse for: {$stock->symbol}");

            // Validate dates
            if ($fromDate > $toDate) {
                $this->error('From date must be before or equal to to date');
                return 1;
            }

            // Get all trading dates in range
            $tradingDates = DB::table('stock_prices')
                ->where('stock_id', $stockId)
                ->whereBetween('date', [$fromDate, $toDate])
                ->orderBy('date')
                ->distinct('date')
                ->pluck('date')
                ->toArray();

            if (empty($tradingDates)) {
                $this->warn("No trading data found for stock {$stock->symbol} in range {$fromDate} to {$toDate}");
                return 0;
            }

            $this->info("Processing {$stock->symbol} for " . count($tradingDates) . ' trading dates');

            $processed = 0;
            $failed = 0;

            DB::beginTransaction();

            try {
                foreach ($tradingDates as $date) {
                    try {
                        // Calculate unified analysis for this stock on this date
                        $analysis = $this->stockAnalysisService->analyzeStock(
                            $stockId,
                            $date,
                            $stock->sector_id
                        );

                        if (!$analysis || !isset($analysis['stock_strength_score'])) {
                            $failed++;
                            continue;
                        }

                        // Upsert stock score
                        $simpleState = $this->calculateSimpleState($analysis);

                        SectorStockScore::updateOrCreate(
                            [
                                'stock_id' => $stockId,
                                'date' => $date,
                            ],
                            [
                                'sector_id' => $stock->sector_id,
                                'relative_leadership_score' => $analysis['relative_leadership_score'] ?? null,
                                'trend_structure_score' => $analysis['trend_structure_score'] ?? null,
                                'momentum_score' => $analysis['momentum_score'] ?? null,
                                'participation_score' => $analysis['participation_score'] ?? null,
                                'stock_strength_score' => $analysis['stock_strength_score'] ?? null,
                                'watch_score' => $analysis['watch_score'] ?? null,
                                'simple_state' => $simpleState,
                                'metadata' => json_encode($analysis['metadata'] ?? []),
                            ]
                        );

                        $processed++;

                        if ($processed % 50 === 0) {
                            $this->info("  {$processed} dates processed...");
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to calculate stock analysis", [
                            'stock_id' => $stockId,
                            'date' => $date,
                            'error' => $e->getMessage(),
                        ]);
                        $failed++;
                    }
                }

                DB::commit();

                // Summary
                $this->newLine();
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Stock', $stock->symbol],
                        ['Date Range', "{$fromDate} to {$toDate}"],
                        ['Trading Dates', count($tradingDates)],
                        ['Processed', $processed],
                        ['Failed', $failed],
                    ]
                );

                $this->info("Market pulse calculation complete for {$stock->symbol}");
                return 0;
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Transaction failed during stock market pulse calculation', [
                    'stock_id' => $stockId,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Calculation failed: {$e->getMessage()}");
                return 1;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->error("Stock not found: {$this->argument('stockId')}");
            return 1;
        } catch (\Exception $e) {
            Log::error('Unexpected error in market pulse stock command', [
                'error' => $e->getMessage(),
            ]);
            $this->error("Error: {$e->getMessage()}");
            return 1;
        }
    }

    private function calculateSimpleState(array $analysis): string
    {
        $strength = $analysis['stock_strength_score'] ?? 0;

        if ($strength >= 70) {
            return 'strong';
        } elseif ($strength >= 50) {
            return 'moderate';
        } elseif ($strength >= 30) {
            return 'weak';
        }
        return 'very_weak';
    }
}
