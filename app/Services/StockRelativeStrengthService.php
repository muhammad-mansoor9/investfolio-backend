<?php

namespace App\Services;

use App\Models\Index;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Support\Collection;

class StockRelativeStrengthService
{
    /**
     * Calculate stock RS vs KSE100 (market)
     * Returns the ratio and momentum
     */
    public function calculateStockRSMarket(string $stockId, string $benchmarkId, string $date): array
    {
        $stock = Stock::findOrFail($stockId);
        $benchmark = Index::findOrFail($benchmarkId);

        // Get stock index value (synthetic daily return / index)
        $stockIndexValue = $this->getOrCalculateStockIndex($stockId, $date);
        $benchmarkClose = $this->getBenchmarkClose($benchmarkId, $date);

        if (!$stockIndexValue || !$benchmarkClose) {
            return [
                'stock_rs_market' => null,
                'stock_rs_market_momentum' => null,
            ];
        }

        // Raw RS = stock / benchmark
        $rawRS = ($stockIndexValue / $benchmarkClose) * 100;

        // RS Ratio (EMA-based)
        $rsRatio = $this->calculateRSRatio($rawRS, stockId: $stockId, benchmarkId: $benchmarkId);

        // RS Momentum
        $rsMomentum = $this->calculateRSMomentum($rsRatio, $stockId, $benchmarkId, $date);

        return [
            'stock_rs_market' => $rawRS,
            'stock_rs_market_momentum' => $rsMomentum,
            'stock_rs_market_ratio' => $rsRatio,
        ];
    }

    /**
     * Calculate stock RS vs its own sector
     */
    public function calculateStockRSSector(string $stockId, string $sectorId, string $date): array
    {
        $stock = Stock::findOrFail($stockId);

        // Get stock index value
        $stockIndexValue = $this->getOrCalculateStockIndex($stockId, $date);

        // Get sector index value (from SectorRotationMetric if available)
        $sectorIndexValue = $this->getSectorIndexValue($sectorId, $date);

        if (!$stockIndexValue || !$sectorIndexValue) {
            return [
                'stock_rs_sector' => null,
                'stock_rs_sector_momentum' => null,
            ];
        }

        // Raw RS = stock / sector
        $rawRS = ($stockIndexValue / $sectorIndexValue) * 100;

        // RS Ratio
        $rsRatio = $this->calculateRSRatio($rawRS, $stockId, $sectorId);

        // RS Momentum
        $rsMomentum = $this->calculateRSMomentum($rsRatio, $stockId, $sectorId, $date);

        return [
            'stock_rs_sector' => $rawRS,
            'stock_rs_sector_momentum' => $rsMomentum,
            'stock_rs_sector_ratio' => $rsRatio,
        ];
    }

    /**
     * Get or calculate stock index value for a date
     * Uses historical stock prices to build a synthetic index
     */
    private function getOrCalculateStockIndex(string $stockId, string $date): ?float
    {
        // For now, use the close price as a proxy for stock index
        // In production, this would calculate a normalized index series

        $price = StockPrice::where('stock_id', $stockId)
            ->where('date', $date)
            ->first();

        return $price ? (float) $price->close : null;
    }

    /**
     * Get benchmark close price for a date
     */
    private function getBenchmarkClose(string $benchmarkId, string $date): ?float
    {
        $indexPrice = \App\Models\IndexPrice::where('index_id', $benchmarkId)
            ->where('date', $date)
            ->first();

        return $indexPrice ? (float) $indexPrice->close : null;
    }

    /**
     * Get sector index value for a date
     */
    private function getSectorIndexValue(string $sectorId, string $date): ?float
    {
        $metric = \App\Models\SectorRotationMetric::forSector($sectorId)
            ->where('date', $date)
            ->first();

        return $metric?->sector_index_value ? (float) $metric->sector_index_value : null;
    }

    /**
     * Calculate RS Ratio from raw RS series
     * Uses EMA fast/slow to smooth the RS line
     */
    private function calculateRSRatio(float $rawRS, string $stockId, string $compareId): ?float
    {
        // This would use EMA calculation on historical RS values
        // For now, return the raw RS as a placeholder
        // Production version would query historical data and apply EMA

        return $rawRS;
    }

    /**
     * Calculate RS Momentum from RS Ratio
     * Momentum = RS Ratio(t) / RS Ratio(t-n)
     */
    private function calculateRSMomentum(float $rsRatio, string $stockId, string $compareId, string $date): ?float
    {
        // This would query historical RS Ratio and calculate momentum
        // For now, return null as placeholder
        // Production version would fetch prior period RS Ratio

        return null;
    }
}
