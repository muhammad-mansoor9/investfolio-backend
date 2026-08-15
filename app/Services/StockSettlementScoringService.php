<?php

namespace App\Services;

use App\Models\UinSettlementData;
use Illuminate\Support\Collection;

class StockSettlementScoringService
{
    /**
     * Calculate settlement score for a stock
     * Evaluates current settlement vs historical baseline
     */
    public function calculateSettlementScore(string $stockId, string $date): float
    {
        $currentSettlement = $this->getCurrentSettlement($stockId, $date);
        if (!$currentSettlement) {
            return 0;
        }

        $baseline20D = $this->getBaselineSettlement($stockId, $date, 20);
        $trend5D = $this->getSettlementTrend($stockId, $date, 5);

        $score = 0;

        // Level vs baseline (40 points)
        if ($baseline20D && $currentSettlement['percentage'] > 0) {
            $delta = $currentSettlement['percentage'] - $baseline20D['percentage'];
            if ($delta >= 20) {
                $score += 40;
            } elseif ($delta >= 10) {
                $score += 30;
            } elseif ($delta >= 0) {
                $score += 20;
            } elseif ($delta >= -10) {
                $score += 10;
            }
        }

        // Trend (30 points)
        if ($trend5D && $trend5D['5d_average'] > 0) {
            $delta = $trend5D['5d_average'] - ($baseline20D['percentage'] ?? 0);
            if ($delta >= 10) {
                $score += 30;
            } elseif ($delta >= 0) {
                $score += 20;
            } elseif ($delta >= -10) {
                $score += 10;
            }
        }

        // Settlement value activity (30 points)
        if ($currentSettlement['settlement_value'] && $baseline20D && $baseline20D['avg_settlement_value']) {
            $ratio = $currentSettlement['settlement_value'] / $baseline20D['avg_settlement_value'];
            if ($ratio >= 1.5) {
                $score += 30;
            } elseif ($ratio >= 1.3) {
                $score += 25;
            } elseif ($ratio >= 1.0) {
                $score += 15;
            }
        }

        return min($score, 100);
    }

    /**
     * Get current settlement data for a stock
     */
    public function getCurrentSettlement(string $stockId, string $date): ?array
    {
        $settlement = UinSettlementData::where('stock_id', $stockId)
            ->where('settlement_date', $date)
            ->first();

        if (!$settlement) {
            return null;
        }

        return [
            'uin_percentage_value' => (float) $settlement->uin_percentage_value,
            'uin_percentage_volume' => (float) $settlement->uin_percentage_volume,
            'percentage' => (float) $settlement->uin_percentage_value, // Use value %
            'settlement_value' => (float) $settlement->uin_settlement_value,
            'settlement_volume' => (float) $settlement->uin_settlement_volume,
            'trade_value' => (float) $settlement->trade_value,
            'trade_volume' => (float) $settlement->trade_volume,
        ];
    }

    /**
     * Get baseline settlement metrics for a stock (recent period)
     */
    public function getBaselineSettlement(string $stockId, string $date, int $days = 20): ?array
    {
        $startDate = now()->parse($date)->subDays($days)->toDateString();

        $settlements = UinSettlementData::where('stock_id', $stockId)
            ->whereBetween('settlement_date', [$startDate, $date])
            ->where('settlement_date', '!=', $date) // Exclude current day
            ->get();

        if ($settlements->isEmpty()) {
            return null;
        }

        $percentages = $settlements->pluck('uin_percentage_value')->filter()->toArray();
        $settlementValues = $settlements->pluck('uin_settlement_value')->filter()->toArray();

        if (empty($percentages)) {
            return null;
        }

        return [
            'percentage' => $this->median($percentages),
            'avg_percentage' => collect($percentages)->avg(),
            'avg_settlement_value' => collect($settlementValues)->avg(),
            'median_settlement_value' => $this->median($settlementValues),
        ];
    }

    /**
     * Get settlement trend (recent change)
     */
    public function getSettlementTrend(string $stockId, string $date, int $days = 5): ?array
    {
        $startDate = now()->parse($date)->subDays($days)->toDateString();

        $settlements = UinSettlementData::where('stock_id', $stockId)
            ->whereBetween('settlement_date', [$startDate, $date])
            ->orderBy('settlement_date')
            ->get();

        if ($settlements->isEmpty()) {
            return null;
        }

        $percentages = $settlements->pluck('uin_percentage_value')->filter()->toArray();

        return [
            '5d_average' => collect($percentages)->avg(),
            'latest' => end($percentages),
            'earliest' => reset($percentages),
        ];
    }

    /**
     * Calculate median of an array
     */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = (int) ($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
