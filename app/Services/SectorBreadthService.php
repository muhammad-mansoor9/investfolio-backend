<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SectorBreadthService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const TIMEFRAME = '1D';

    /**
     * Calculate technical breadth metrics for a sector on a given date
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return array Percentages for each metric with validation metadata
     */
    public function calculateBreadthMetrics(string $sectorId, string $date): array
    {
        $cacheKey = "breadth:metrics:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            Log::debug('Calculating breadth metrics', ['sector_id' => $sectorId, 'date' => $date]);

            // Get all active stocks in sector
            $stocks = DB::table('stocks')
                ->select('id', 'symbol')
                ->where('sector_id', $sectorId)
                ->whereRaw('is_active = true')
                ->get();

            if ($stocks->isEmpty()) {
                Log::warning('No active stocks found in sector', ['sector_id' => $sectorId]);
                return $this->emptyBreadthMetrics();
            }

            $stockIds = $stocks->pluck('id')->toArray();

            // Fetch all indicator data for stocks on the given date
            $indicators = DB::table('stock_indicators')
                ->where('timeframe', self::TIMEFRAME)
                ->where('date', $date)
                ->whereIn('stock_id', $stockIds)
                ->get(['stock_id', 'data']);

            // Build indicator map
            $indicatorMap = $indicators->keyBy('stock_id')->map(fn($row) =>
                is_string($row->data) ? json_decode($row->data, true) : $row->data
            );

            $validation = $this->validateIndicatorData($stocks->toArray(), $indicatorMap->toArray());

            if ($validation['eligible'] === 0) {
                Log::warning('No eligible stocks with indicator data', ['sector_id' => $sectorId, 'date' => $date]);
                return $this->emptyBreadthMetrics($validation);
            }

            // Calculate percentages for each metric
            $metrics = [];
            $emaMetrics = ['ema20', 'ema50', 'ema100', 'ema200'];

            foreach ($emaMetrics as $ema) {
                $metrics["price_above_{$ema}"] = $this->calculatePercentageAboveEMA(
                    $stocks,
                    $indicatorMap,
                    $ema,
                    $validation['eligible']
                );
            }

            $metrics['rsi_above_50'] = $this->calculateRSIPercentage($stocks, $indicatorMap, $validation['eligible']);
            $metrics['macd_bullish'] = $this->calculateMACDBullishPercentage($stocks, $indicatorMap, $validation['eligible']);
            $metrics['di_plus_above_di_minus'] = $this->calculateDIPercentage($stocks, $indicatorMap, $validation['eligible']);

            return [
                'sector_id' => $sectorId,
                'date' => $date,
                'metrics' => $metrics,
                'validation' => $validation,
            ];
        });
    }

    /**
     * Calculate weighted breadth score from individual metrics
     * Uses weights from config/market_pulse.php
     *
     * @param array $metrics Result from calculateBreadthMetrics()
     * @return float Breadth score 0-100
     */
    public function calculateBreadthScore(array $metrics): float
    {
        $metricsData = $metrics['metrics'] ?? [];
        if (empty($metricsData)) {
            return 0.0;
        }

        $config = config('market_pulse.technical_breadth.metrics', []);
        $totalWeight = 0;
        $weightedScore = 0;

        foreach ($metricsData as $metricName => $percentage) {
            if (!isset($config[$metricName])) {
                continue;
            }

            $weight = $config[$metricName]['weight'] ?? 0;
            $totalWeight += $weight;

            // Percentage already 0-100, weight it
            $weightedScore += ($percentage * $weight) / 100;
        }

        if ($totalWeight === 0) {
            return 0.0;
        }

        $score = ($weightedScore / $totalWeight) * 100;
        return min(100, max(0, round($score, 2)));
    }

    /**
     * Get breadth trend (5-day and 20-day changes)
     *
     * @param string $sectorId Sector UUID
     * @param string $date Reference date (Y-m-d)
     * @return array Trend data with 5d and 20d changes
     */
    public function getBreadthTrend(string $sectorId, string $date): array
    {
        $refDate = Carbon::parse($date);
        $date5dAgo = $refDate->copy()->subDays(5)->format('Y-m-d');
        $date20dAgo = $refDate->copy()->subDays(20)->format('Y-m-d');

        $current = $this->calculateBreadthMetrics($sectorId, $date);
        $fiveDaysAgo = $this->calculateBreadthMetrics($sectorId, $date5dAgo);
        $twentyDaysAgo = $this->calculateBreadthMetrics($sectorId, $date20dAgo);

        $currentScore = $this->calculateBreadthScore($current);
        $fiveDayScore = $this->calculateBreadthScore($fiveDaysAgo);
        $twentyDayScore = $this->calculateBreadthScore($twentyDaysAgo);

        return [
            'sector_id' => $sectorId,
            'date' => $date,
            'current_score' => $currentScore,
            'change_5d' => round($currentScore - $fiveDayScore, 2),
            'change_5d_percent' => $fiveDayScore > 0 ? round((($currentScore - $fiveDayScore) / $fiveDayScore) * 100, 2) : 0,
            'change_20d' => round($currentScore - $twentyDayScore, 2),
            'change_20d_percent' => $twentyDayScore > 0 ? round((($currentScore - $twentyDayScore) / $twentyDayScore) * 100, 2) : 0,
            'trend_direction' => match (true) {
                $currentScore - $fiveDayScore > 5 => 'strengthening',
                $currentScore - $fiveDayScore < -5 => 'weakening',
                default => 'stable',
            },
        ];
    }

    /**
     * Validate which stocks have complete indicator data
     * Excludes stocks with missing indicators from calculations
     *
     * @param array $stocks Stock records with 'id' and 'symbol'
     * @param array $indicatorMap Map of stock_id => indicator data
     * @return array Validation summary
     */
    public function validateIndicatorData(array $stocks, array $indicatorMap): array
    {
        $total = count($stocks);
        $eligible = count($indicatorMap);
        $percentage = $total > 0 ? round(($eligible / $total) * 100, 2) : 0;

        $requiredFields = ['close', 'ema20', 'ema50', 'ema100', 'ema200', 'rsi', 'macd', 'di_plus', 'di_minus'];
        $validCount = 0;

        foreach ($indicatorMap as $data) {
            $hasAllFields = true;
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || $data[$field] === null) {
                    $hasAllFields = false;
                    break;
                }
            }
            if ($hasAllFields) {
                $validCount++;
            }
        }

        return [
            'total_stocks' => $total,
            'eligible' => $eligible,
            'valid' => $validCount,
            'coverage_percentage' => $percentage,
            'message' => $eligible === 0 ? 'No indicator data available for date' : null,
        ];
    }

    private function calculatePercentageAboveEMA(
        $stocks,
        $indicatorMap,
        string $emaType,
        int $eligible
    ): float {
        if ($eligible === 0) {
            return 0.0;
        }

        $count = 0;
        foreach ($stocks as $stock) {
            $indicators = $indicatorMap[$stock->id] ?? null;
            if ($indicators && isset($indicators['close'], $indicators[$emaType])) {
                if ((float)$indicators['close'] > (float)$indicators[$emaType]) {
                    $count++;
                }
            }
        }

        return round(($count / $eligible) * 100, 2);
    }

    private function calculateRSIPercentage($stocks, $indicatorMap, int $eligible): float
    {
        if ($eligible === 0) {
            return 0.0;
        }

        $count = 0;
        $rsiThreshold = config('market_pulse.stock_analysis.rsi_interpretation.neutral_recovery', 50);

        foreach ($stocks as $stock) {
            $indicators = $indicatorMap[$stock->id] ?? null;
            if ($indicators && isset($indicators['rsi']) && $indicators['rsi'] !== null) {
                if ((float)$indicators['rsi'] > $rsiThreshold) {
                    $count++;
                }
            }
        }

        return round(($count / $eligible) * 100, 2);
    }

    private function calculateMACDBullishPercentage($stocks, $indicatorMap, int $eligible): float
    {
        if ($eligible === 0) {
            return 0.0;
        }

        $count = 0;
        foreach ($stocks as $stock) {
            $indicators = $indicatorMap[$stock->id] ?? null;
            if ($indicators && isset($indicators['macd'], $indicators['macd_signal'])) {
                if ($indicators['macd'] !== null && $indicators['macd_signal'] !== null) {
                    if ((float)$indicators['macd'] > (float)$indicators['macd_signal']) {
                        $count++;
                    }
                }
            }
        }

        return round(($count / $eligible) * 100, 2);
    }

    private function calculateDIPercentage($stocks, $indicatorMap, int $eligible): float
    {
        if ($eligible === 0) {
            return 0.0;
        }

        $count = 0;
        foreach ($stocks as $stock) {
            $indicators = $indicatorMap[$stock->id] ?? null;
            if ($indicators && isset($indicators['di_plus'], $indicators['di_minus'])) {
                if ($indicators['di_plus'] !== null && $indicators['di_minus'] !== null) {
                    if ((float)$indicators['di_plus'] > (float)$indicators['di_minus']) {
                        $count++;
                    }
                }
            }
        }

        return round(($count / $eligible) * 100, 2);
    }

    private function emptyBreadthMetrics(array $validation = []): array
    {
        return [
            'metrics' => [
                'price_above_ema20' => 0.0,
                'price_above_ema50' => 0.0,
                'price_above_ema100' => 0.0,
                'price_above_ema200' => 0.0,
                'rsi_above_50' => 0.0,
                'macd_bullish' => 0.0,
                'di_plus_above_di_minus' => 0.0,
            ],
            'validation' => $validation ?: [
                'total_stocks' => 0,
                'eligible' => 0,
                'valid' => 0,
                'coverage_percentage' => 0.0,
            ],
        ];
    }
}
