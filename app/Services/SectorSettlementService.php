<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SectorSettlementService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const BASELINE_PERIODS = 20;

    /**
     * Calculate settlement breadth for a sector
     * Percentage of stocks with elevated UIN settlement participation
     *
     * @param string $sectorId Sector UUID
     * @param string $date Settlement date (Y-m-d)
     * @return array Settlement breadth metrics
     */
    public function calculateSettlementBreadth(string $sectorId, string $date): array
    {
        $cacheKey = "settlement:breadth:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            Log::debug('Calculating settlement breadth', ['sector_id' => $sectorId, 'date' => $date]);

            // Get all active stocks in sector
            $stocks = DB::table('stocks')
                ->select('id', 'symbol')
                ->where('sector_id', $sectorId)
                ->whereRaw('is_active = true')
                ->get();

            if ($stocks->isEmpty()) {
                Log::warning('No active stocks found in sector', ['sector_id' => $sectorId]);
                return $this->emptySettlementBreadth();
            }

            $symbols = $stocks->pluck('symbol')->toArray();
            $baseline_threshold = config('market_pulse.settlement.metrics.elevated_settlement_value.ratio_threshold', 1.3);

            // Calculate metrics for each stock
            $above_baseline_count = 0;
            $elevated_value_count = 0;
            $total_with_data = 0;

            foreach ($symbols as $symbol) {
                $context = $this->getStockSettlementContext($symbol, $date);

                if ($context['has_data']) {
                    $total_with_data++;

                    if ($context['above_baseline']) {
                        $above_baseline_count++;
                    }

                    if ($context['settlement_ratio'] >= $baseline_threshold) {
                        $elevated_value_count++;
                    }
                }
            }

            $coverage_percentage = $total_with_data > 0 ? round(($total_with_data / count($symbols)) * 100, 2) : 0;

            return [
                'sector_id' => $sectorId,
                'date' => $date,
                'metrics' => [
                    'total_stocks' => count($symbols),
                    'stocks_with_data' => $total_with_data,
                    'above_own_baseline' => $total_with_data > 0 ? round(($above_baseline_count / $total_with_data) * 100, 2) : 0,
                    'elevated_settlement_value' => $total_with_data > 0 ? round(($elevated_value_count / $total_with_data) * 100, 2) : 0,
                ],
                'coverage_percentage' => $coverage_percentage,
                'coverage_status' => $coverage_percentage >= 50 ? 'adequate' : 'sparse',
            ];
        });
    }

    /**
     * Calculate settlement breadth score (0-100)
     * Combines above-baseline and elevated-value metrics with weights
     *
     * @param array $breadthMetrics Result from calculateSettlementBreadth()
     * @return float Score 0-100
     */
    public function calculateSettlementScore(array $breadthMetrics): float
    {
        $metrics = $breadthMetrics['metrics'] ?? [];
        if (empty($metrics) || $breadthMetrics['coverage_percentage'] < 10) {
            return 0.0;
        }

        $weights = config('market_pulse.settlement.metrics', []);
        $above_baseline_weight = $weights['above_own_baseline']['weight'] ?? 50;
        $elevated_value_weight = $weights['elevated_settlement_value']['weight'] ?? 30;
        $price_confirmation_weight = $weights['price_confirmation']['weight'] ?? 20;

        $above_baseline_score = ($metrics['above_own_baseline'] ?? 0) * ($above_baseline_weight / 100);
        $elevated_value_score = ($metrics['elevated_settlement_value'] ?? 0) * ($elevated_value_weight / 100);

        // Price confirmation is a placeholder here (would need additional price analysis)
        $price_confirmation_score = 50 * ($price_confirmation_weight / 100);

        $total = $above_baseline_score + $elevated_value_score + $price_confirmation_score;

        return round($total, 2);
    }

    /**
     * Get settlement context for a single stock
     * Compares current settlement to its 20-day baseline
     *
     * @param string $stockSymbol Stock symbol
     * @param string $date Settlement date (Y-m-d)
     * @return array Settlement metrics with baseline comparison
     */
    public function getStockSettlementContext(string $stockSymbol, string $date): array
    {
        $refDate = Carbon::parse($date);
        $baseline_start = $refDate->copy()->subDays(self::BASELINE_PERIODS)->format('Y-m-d');
        $baseline_end = $refDate->copy()->subDays(1)->format('Y-m-d');

        // Get current settlement data
        $current = DB::table('uin_settlement_data')
            ->where('symbol', $stockSymbol)
            ->where('settlement_date', $date)
            ->first(['uin_percentage_value', 'uin_settlement_value', 'trade_value']);

        if (!$current) {
            return [
                'symbol' => $stockSymbol,
                'date' => $date,
                'has_data' => false,
                'current_uin_percentage' => null,
                'baseline_uin_percentage' => null,
                'delta' => null,
                'above_baseline' => false,
                'settlement_ratio' => 1.0,
                'trend' => null,
            ];
        }

        // Get baseline (20-day median)
        $baseline = DB::table('uin_settlement_data')
            ->where('symbol', $stockSymbol)
            ->whereBetween('settlement_date', [$baseline_start, $baseline_end])
            ->get(['uin_percentage_value', 'uin_settlement_value'])
            ->filter(fn($r) => $r->uin_percentage_value !== null)
            ->values();

        if ($baseline->isEmpty()) {
            $median_percentage = null;
            $median_value = null;
        } else {
            $percentages = $baseline->pluck('uin_percentage_value')->sort()->values()->toArray();
            $values = $baseline->pluck('uin_settlement_value')->filter()->sort()->values()->toArray();

            $median_percentage = $this->getMedian($percentages);
            $median_value = !empty($values) ? $this->getMedian($values) : null;
        }

        $current_percentage = (float)$current->uin_percentage_value;
        $delta = $median_percentage !== null ? round($current_percentage - $median_percentage, 2) : null;
        $above_baseline = $delta !== null && $delta > 0;

        // Settlement ratio: current value vs baseline median value
        $settlement_ratio = 1.0;
        if ($median_value !== null && $median_value > 0) {
            $current_value = (float)($current->uin_settlement_value ?? 0);
            $settlement_ratio = round($current_value / $median_value, 2);
        }

        // Trend: improvement or deterioration
        $trend = null;
        if ($baseline->count() >= 5) {
            $recent = $baseline->slice(-5)->pluck('uin_percentage_value')->avg();
            $older = $baseline->slice(0, 5)->pluck('uin_percentage_value')->avg();
            if ($recent > $older) {
                $trend = 'improving';
            } elseif ($recent < $older) {
                $trend = 'deteriorating';
            } else {
                $trend = 'stable';
            }
        }

        return [
            'symbol' => $stockSymbol,
            'date' => $date,
            'has_data' => true,
            'current_uin_percentage' => round($current_percentage, 2),
            'baseline_uin_percentage' => $median_percentage !== null ? round($median_percentage, 2) : null,
            'delta' => $delta,
            'above_baseline' => $above_baseline,
            'settlement_ratio' => $settlement_ratio,
            'baseline_samples' => $baseline->count(),
            'trend' => $trend,
        ];
    }

    /**
     * Get settlement trend (5-day and 20-day changes) for a sector
     *
     * @param string $sectorId Sector UUID
     * @param string $date Reference date (Y-m-d)
     * @return array Trend data
     */
    public function getSettlementTrend(string $sectorId, string $date): array
    {
        $refDate = Carbon::parse($date);
        $date5dAgo = $refDate->copy()->subDays(5)->format('Y-m-d');
        $date20dAgo = $refDate->copy()->subDays(20)->format('Y-m-d');

        $current = $this->calculateSettlementBreadth($sectorId, $date);
        $fiveDaysAgo = $this->calculateSettlementBreadth($sectorId, $date5dAgo);
        $twentyDaysAgo = $this->calculateSettlementBreadth($sectorId, $date20dAgo);

        $currentScore = $this->calculateSettlementScore($current);
        $fiveDayScore = $this->calculateSettlementScore($fiveDaysAgo);
        $twentyDayScore = $this->calculateSettlementScore($twentyDaysAgo);

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
     * Get median from sorted array
     */
    private function getMedian(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }

        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    private function emptySettlementBreadth(): array
    {
        return [
            'metrics' => [
                'total_stocks' => 0,
                'stocks_with_data' => 0,
                'above_own_baseline' => 0.0,
                'elevated_settlement_value' => 0.0,
            ],
            'coverage_percentage' => 0.0,
            'coverage_status' => 'sparse',
        ];
    }
}
