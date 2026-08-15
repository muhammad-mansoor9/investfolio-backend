<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Index;
use App\Models\IndexPrice;
use App\Models\MarketRegimeMetric;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MarketRegimeService
{
    protected MarketRegimeCalculationService $calculationService;

    public function __construct(MarketRegimeCalculationService $calculationService)
    {
        $this->calculationService = $calculationService;
    }

    /**
     * Calculate and persist daily market regime metrics for a specific index
     * Returns the calculated regime with all scores and metadata
     */
    public function calculateAndPersistDailyMetrics(string $indexId, ?\DateTime $date = null): ?array
    {
        try {
            $date = $date ?? now();
            $dateStr = $date->format('Y-m-d');

            // Get historical index prices (need sufficient data for indicators)
            // EMA200 needs at least 200 data points, so fetch more
            $indexPrices = $this->getHistoricalIndexPrices($indexId, $date, 250);

            if (empty($indexPrices)) {
                Log::warning("No index price data available for calculation", [
                    'index_id' => $indexId,
                    'date' => $dateStr,
                ]);
                return null;
            }

            // Calculate component scores
            $structuralResult = $this->calculationService->calculateStructuralScore($indexPrices, $date);
            $directionalResult = $this->calculationService->calculateDirectionalScore($indexPrices, $date);
            $tacticalResult = $this->calculationService->calculateTacticalScore($indexPrices, $date);

            $structuralScore = $structuralResult['score'];
            $directionalScore = $directionalResult['score'];
            $tacticalScore = $tacticalResult['score'];

            // Combine into regime score
            $regimeData = $this->calculationService->combineComponentsIntoRegimeScore(
                $structuralScore,
                $directionalScore,
                $tacticalScore
            );

            // Extract metadata for explainability
            $metadata = $this->calculationService->extractMetadataForExplainability(
                $structuralResult['details'],
                $directionalResult['details'],
                $tacticalResult['details']
            );

            // Prepare metric record
            $metricData = [
                'index_id' => $indexId,
                'date' => $date->format('Y-m-d'),
                'regime_score' => $regimeData['regime_score'],
                'structural_trend' => $structuralResult['state'],
                'directional_bias' => $directionalResult['state'],
                'tactical_momentum' => $tacticalResult['state'],
                'regime' => $regimeData['regime'],
                'metadata' => $metadata,
            ];

            // Upsert metric record
            $metric = MarketRegimeMetric::updateOrCreate(
                [
                    'index_id' => $indexId,
                    'date' => $date->format('Y-m-d'),
                ],
                $metricData
            );

            Log::info('Daily market regime metrics calculated and persisted', [
                'index_id' => $indexId,
                'date' => $dateStr,
                'regime' => $regimeData['regime'],
                'regime_score' => $regimeData['regime_score'],
            ]);

            // Cache the result for quick access
            $cacheKey = "psx:market_regime:{$indexId}:{$dateStr}";
            Cache::put($cacheKey, $metricData, CacheService::CACHE_TTL_DAILY);

            return [
                'metric_id' => $metric->id,
                'regime' => $regimeData['regime'],
                'regime_score' => $regimeData['regime_score'],
                'structural_score' => $structuralScore,
                'directional_score' => $directionalScore,
                'tactical_score' => $tacticalScore,
                'structural_state' => $structuralResult['state'],
                'directional_state' => $directionalResult['state'],
                'tactical_state' => $tacticalResult['state'],
                'metadata' => $metadata,
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating and persisting daily metrics', [
                'error' => $e->getMessage(),
                'index_id' => $indexId,
                'date' => $date?->format('Y-m-d'),
            ]);
            return null;
        }
    }

    public function getCurrentRegime(string $indexId): ?array
    {
        try {
            $cacheKey = "psx:market_regime:current:{$indexId}";
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $metric = MarketRegimeMetric::where('index_id', $indexId)
                ->orderBy('date', 'desc')
                ->first();

            if (!$metric) {
                return null;
            }

            $result = [
                'regime' => $metric->regime,
                'regime_score' => round($metric->regime_score, 2),
                'structural_trend' => $metric->structural_state,
                'directional_bias' => $metric->directional_state,
                'tactical_momentum' => $metric->tactical_state,
                'date' => $metric->date->format('Y-m-d'),
            ];

            Cache::put($cacheKey, $result, 60 * 60);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error getting current regime', ['error' => $e->getMessage(), 'index_id' => $indexId]);
            return null;
        }
    }

    /**
     * Batch calculate daily metrics for all active indices
     * Useful for scheduled jobs
     */
    public function calculateDailyMetricsForAllIndices(?\DateTime $date = null): array
    {
        try {
            $date = $date ?? now();
            $results = [];

            // Get all active indices
            $indices = Index::active()->get();

            foreach ($indices as $index) {
                $result = $this->calculateAndPersistDailyMetrics($index->id, $date);
                if ($result) {
                    $results[$index->symbol] = $result;
                }
            }

            Log::info('Batch daily metrics calculation complete', [
                'date' => $date->format('Y-m-d'),
                'indices_processed' => count($results),
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('Error in batch daily metrics calculation', [
                'error' => $e->getMessage(),
                'date' => $date?->format('Y-m-d'),
            ]);
            return [];
        }
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Get historical index prices for calculation
     * Returns prices in ascending order (oldest first)
     */
    protected function getHistoricalIndexPrices(string $indexId, \DateTime $date, int $count = 250): array
    {
        try {
            $prices = IndexPrice::where('index_id', $indexId)
                ->where('date', '<=', $date->format('Y-m-d'))
                ->orderBy('date', 'desc')
                ->limit($count)
                ->get()
                ->map(fn($p) => [
                    'date' => $p->date->format('Y-m-d'),
                    'open' => $p->open,
                    'high' => $p->high,
                    'low' => $p->low,
                    'close' => $p->close,
                    'volume' => $p->volume,
                ])
                ->toArray();

            // Reverse to get ascending order (oldest first)
            return array_reverse($prices);
        } catch (\Exception $e) {
            Log::error('Error fetching historical index prices', [
                'error' => $e->getMessage(),
                'index_id' => $indexId,
                'date' => $date->format('Y-m-d'),
            ]);
            return [];
        }
    }
}
