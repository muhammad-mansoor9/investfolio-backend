<?php

namespace App\Services;

use App\Models\Sector;
use App\Models\Stock;
use App\Models\FinancialRatio;
use Illuminate\Support\Collection;

class StockScreenerService
{
    protected RatioCalculationService $ratioCalculator;

    public function __construct(RatioCalculationService $ratioCalculator)
    {
        $this->ratioCalculator = $ratioCalculator;
    }

    /**
     * Get complete screener data for a sector
     *
     * @param string $sectorId
     * @return array
     */
    public function getScreenerData(string $sectorId): array
    {
        // Get sector with relationships
        $sector = Sector::with(['activeStocks' => function($query) {
            $query->orderBy('symbol', 'asc');
        }])->findOrFail($sectorId);

        if ($sector->activeStocks->isEmpty()) {
            return [
                'sector' => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                ],
                'stocks_count' => 0,
                'message' => 'No active stocks found in this sector',
            ];
        }

        // **OPTIMIZATION: Batch load all financial data for all stocks at once**
        // This prevents N+1 query problems by loading all data in ONE query
        $this->ratioCalculator->preloadFinancialData($sector->activeStocks);

        // Get applicable ratios for this sector
        $ratios = $this->getApplicableRatios($sectorId);

        // Group ratios by category
        $categorizedRatios = $this->categorizeRatios($ratios);

        // Calculate ratio values for all stocks
        $screenerData = $this->buildScreenerData($sector->activeStocks, $categorizedRatios);

        // Clear cache after use to free memory
        $this->ratioCalculator->clearCache();

        return [
            'sector' => [
                'id' => $sector->id,
                'name' => $sector->name,
            ],
            'stocks_count' => $sector->activeStocks->count(),
            'categories' => $screenerData,
        ];
    }

    /**
     * Get applicable ratios for a sector (universal + sector-specific)
     */
    private function getApplicableRatios(string $sectorId): Collection
    {
        return FinancialRatio::active()
            ->where(function($query) use ($sectorId) {
                $query->whereNull('sector_id') // Universal ratios
                ->orWhere('sector_id', $sectorId); // Sector-specific ratios
            })
            ->orderBy('ratio_category', 'asc')
            ->orderByDisplayOrder()
            ->get();
    }

    /**
     * Group ratios by category
     */
    private function categorizeRatios(Collection $ratios): array
    {
        $categorized = [];

        foreach ($ratios as $ratio) {
            $category = $ratio->ratio_category;

            if (!isset($categorized[$category])) {
                $categorized[$category] = [];
            }

            $categorized[$category][] = [
                'id' => $ratio->id,
                'name' => $ratio->ratio_name,
                'description' => $ratio->ratio_description,
                'metadata' => $ratio->metadata,
                'is_critical' => $ratio->isCritical(),
            ];
        }

        return $categorized;
    }

    /**
     * Build complete screener data with calculated ratios
     */
    private function buildScreenerData(Collection $stocks, array $categorizedRatios): array
    {
        $screenerData = [];

        foreach ($categorizedRatios as $category => $ratios) {
            $categoryData = [
                'category_name' => $category,
                'ratios' => array_column($ratios, 'name'),
                'ratio_details' => $ratios,
                'data' => [],
            ];

            // Calculate ratios for each stock
            // Since data is pre-loaded, this will be very fast
            foreach ($stocks as $stock) {
                $stockData = [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'is_shariah' => $stock->is_shariah,
                    'market_cap' => $stock->market_cap ? (float) $stock->market_cap : null,
                    'ratios' => [],
                ];

                foreach ($ratios as $ratio) {
                    $value = $this->ratioCalculator->calculateRatio(
                        $stock,
                        $ratio['name']
                    );

                    $stockData['ratios'][$ratio['name']] = $value;
                }

                $categoryData['data'][] = $stockData;
            }

            // Calculate sector averages for each ratio
            $categoryData['sector_average'] = $this->calculateSectorAverages(
                $categoryData['data'],
                $ratios
            );

            $screenerData[] = $categoryData;
        }

        return $screenerData;
    }

    /**
     * Calculate sector averages for all ratios in a category
     */
    private function calculateSectorAverages(array $stocksData, array $ratios): array
    {
        $averages = [];

        foreach ($ratios as $ratio) {
            $ratioName = $ratio['name'];
            $values = [];

            foreach ($stocksData as $stockData) {
                $value = $stockData['ratios'][$ratioName];
                if ($value !== null && is_numeric($value)) {
                    $values[] = (float) $value;
                }
            }

            $averages[$ratioName] = !empty($values)
                ? round(array_sum($values) / count($values), 2)
                : null;
        }

        return $averages;
    }
}
