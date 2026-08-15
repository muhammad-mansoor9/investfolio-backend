<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sector;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SectorIndexBuilder
{
    /**
     * Build sector return metrics for a specific date
     *
     * Returns free-float weighted and equal-weight sector returns
     */
    public function buildSectorReturnForDate(string $sectorId, string $date): array
    {
        $currentDate = Carbon::parse($date);
        $previousDate = $currentDate->copy()->subDay();

        $stocks = $this->getConstituentStocks($sectorId, $date);

        if ($stocks->isEmpty()) {
            Log::warning('No constituent stocks found for sector', [
                'sector_id' => $sectorId,
                'date' => $date,
            ]);
            return [
                'sector_return' => null,
                'equal_weight_return' => null,
                'metrics' => [
                    'eligible_count' => 0,
                    'coverage_ratio' => 0,
                    'total_count' => 0,
                ],
            ];
        }

        // Load prices for current and previous dates
        $stockIds = $stocks->pluck('id')->toArray();
        $currentPrices = $this->getPricesForDate($stockIds, $currentDate->format('Y-m-d'));
        $previousPrices = $this->getPricesForDate($stockIds, $previousDate->format('Y-m-d'));

        // Calculate weights based on previous session close prices
        $weights = $this->calculateWeightsFromStocks($stocks, $previousPrices);

        // Calculate returns for eligible stocks
        $freeFloatReturns = [];
        $equalWeightReturns = [];
        $validStocks = 0;

        foreach ($stocks as $stock) {
            $currentPrice = $currentPrices[$stock->id] ?? null;
            $previousPrice = $previousPrices[$stock->id] ?? null;

            if (!$currentPrice || !$previousPrice) {
                continue;
            }

            $return = ($currentPrice['close'] - $previousPrice['close']) / $previousPrice['close'] * 100;
            $validStocks++;

            if (isset($weights['free_float'][$stock->id])) {
                $freeFloatReturns[] = $return * ($weights['free_float'][$stock->id] / 100);
            }

            if (isset($weights['equal_weight'][$stock->id])) {
                $equalWeightReturns[] = $return * ($weights['equal_weight'][$stock->id] / 100);
            }
        }

        $sectorReturn = !empty($freeFloatReturns) ? array_sum($freeFloatReturns) : null;
        $equalWeightReturn = !empty($equalWeightReturns) ? array_sum($equalWeightReturns) : null;
        $coverageRatio = $stocks->count() > 0 ? $validStocks / $stocks->count() : 0;

        return [
            'sector_return' => $sectorReturn,
            'equal_weight_return' => $equalWeightReturn,
            'metrics' => [
                'eligible_count' => $validStocks,
                'coverage_ratio' => round($coverageRatio, 4),
                'total_count' => $stocks->count(),
            ],
        ];
    }

    /**
     * Calculate free-float and equal-weight allocations for constituent stocks
     *
     * Returns array with weights keyed by stock_id
     */
    public function calculateWeights(string $sectorId, string $date): array
    {
        $stocks = $this->getConstituentStocks($sectorId, $date);

        if ($stocks->isEmpty()) {
            return ['free_float' => [], 'equal_weight' => []];
        }

        // Use previous session close prices for weighting (avoid look-ahead bias)
        $previousDate = Carbon::parse($date)->subDay()->format('Y-m-d');
        $prices = $this->getPricesForDate($stocks->pluck('id')->toArray(), $previousDate);

        return $this->calculateWeightsFromStocks($stocks, $prices);
    }


    /**
     * Get constituent stocks for a sector, filtering for active status
     */
    public function getConstituentStocks(string $sectorId, string $date): \Illuminate\Database\Eloquent\Collection
    {
        return Stock::where('sector_id', $sectorId)
            ->whereRaw('is_active = true')
            ->where('free_float', '>', 0)
            ->get();
    }

    /**
     * Validate data quality of stocks and their prices
     *
     * Returns metrics: eligible_count, coverage_ratio
     */
    public function validateDataQuality($stocks, array $prices): array
    {
        $eligibleCount = 0;

        foreach ($stocks as $stock) {
            if (isset($prices[$stock->id]) && $prices[$stock->id] !== null) {
                $eligibleCount++;
            }
        }

        $coverageRatio = $stocks->count() > 0 ? $eligibleCount / $stocks->count() : 0;

        return [
            'eligible_count' => $eligibleCount,
            'coverage_ratio' => round($coverageRatio, 4),
        ];
    }

    /**
     * Get stock prices for a specific date
     *
     * Returns array keyed by stock_id with price data
     */
    protected function getPricesForDate(array $stockIds, string $date): array
    {
        if (empty($stockIds)) {
            return [];
        }

        $prices = StockPrice::whereIn('stock_id', $stockIds)
            ->where('date', $date)
            ->get()
            ->keyBy('stock_id')
            ->map(fn($p) => [
                'close' => $p->close,
                'volume' => $p->volume,
                'date' => $p->date,
            ])
            ->toArray();

        return $prices;
    }

    /**
     * Calculate weights from stocks and their prices
     *
     * Uses free_float from stock model and previous session prices
     */
    protected function calculateWeightsFromStocks($stocks, array $prices): array
    {
        $freeFloatWeights = [];
        $totalFreeFloat = 0;
        $validStocks = 0;

        // Calculate total free float for valid stocks
        foreach ($stocks as $stock) {
            if (isset($prices[$stock->id]) && $prices[$stock->id] !== null && $stock->free_float > 0) {
                $totalFreeFloat += $stock->free_float;
                $validStocks++;
            }
        }

        // Calculate normalized weights
        foreach ($stocks as $stock) {
            if (isset($prices[$stock->id]) && $prices[$stock->id] !== null && $stock->free_float > 0) {
                $weight = $totalFreeFloat > 0 ? ($stock->free_float / $totalFreeFloat) * 100 : 0;
                $freeFloatWeights[$stock->id] = round($weight, 4);
            }
        }

        // Equal weight: each valid stock gets equal percentage
        $equalWeightPercentage = $validStocks > 0 ? 100 / $validStocks : 0;
        $equalWeightWeights = [];
        foreach ($stocks as $stock) {
            if (isset($prices[$stock->id]) && $prices[$stock->id] !== null && $stock->free_float > 0) {
                $equalWeightWeights[$stock->id] = round($equalWeightPercentage, 4);
            }
        }

        return [
            'free_float' => $freeFloatWeights,
            'equal_weight' => $equalWeightWeights,
        ];
    }

}
