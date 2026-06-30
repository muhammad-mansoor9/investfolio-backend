<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Stock;
use App\Models\Sector;
use App\Models\PerformanceCriteria;
use Illuminate\Support\Collection;

class PerformanceService
{
    protected $calculationService;

    public function __construct(PerformanceCalculationService $calculationService)
    {
        $this->calculationService = $calculationService;
    }

    /**
     * Get performance analysis for a sector
     */
    public function getSectorPerformance(string $sectorId): array
    {
        $sector = Sector::findOrFail($sectorId);

        // Get all criteria for this sector
        $criteria = PerformanceCriteria::active()
            ->forSector($sectorId)
            ->orderByDisplayOrder()
            ->get();

        if ($criteria->isEmpty()) {
            return [
                'sector' => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                ],
                'stocks_count' => 0,
                'categories' => [],
                'message' => 'No performance criteria found for this sector',
            ];
        }

        // Get all active stocks in this sector
        $stocks = Stock::where('sector_id', $sectorId)
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get();

        if ($stocks->isEmpty()) {
            return [
                'sector' => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                ],
                'stocks_count' => 0,
                'categories' => [],
                'message' => 'No active stocks found in this sector',
            ];
        }

        // Group criteria by category
        $categorizedCriteria = $criteria->groupBy('criteria_category');

        $categories = [];

        foreach ($categorizedCriteria as $categoryName => $categoryCriteria) {
            $categories[] = $this->buildCategoryData(
                $categoryName,
                $categoryCriteria,
                $stocks
            );
        }

        return [
            'sector' => [
                'id' => $sector->id,
                'name' => $sector->name,
            ],
            'stocks_count' => $stocks->count(),
            'categories' => $categories,
        ];
    }

    /**
     * Build category data with stock scores
     */
    protected function buildCategoryData(
        string $categoryName,
        Collection $criteria,
        Collection $stocks
    ): array {
        $criteriaDetails = $criteria->map(function ($criterion) {
            return [
                'id' => $criterion->id,
                'name' => $criterion->criteria_name,
                'description' => $criterion->criteria_description,
                'unit' => $criterion->getUnit(),
                'weightage' => $criterion->getWeightage(),
                'metadata' => [
                    'display_order' => $criterion->display_order,
                    'scoring_type' => $criterion->getScoringType(),
                    'reference_values' => $criterion->getReferenceValues(),
                    'is_higher_better' => $criterion->isHigherBetter(),
                ],
            ];
        })->toArray();

        $stocksData = [];
        $categoryAverages = [];

        // Initialize averages for each criterion
        foreach ($criteria as $criterion) {
            $categoryAverages[$criterion->criteria_name] = [
                'sum_value' => 0,
                'sum_score' => 0,
                'count' => 0,
            ];
        }

        foreach ($stocks as $stock) {
            $stockScores = [];
            $totalWeightedScore = 0;
            $totalWeight = 0;

            foreach ($criteria as $criterion) {
                $result = $this->calculationService->calculateScore($stock, $criterion);

                $stockScores[$criterion->criteria_name] = [
                    'value' => $result['value'],
                    'score' => $result['score'],
                    'weighted_score' => $result['weighted_score'],
                ];

                // Accumulate for averages
                if ($result['value'] !== null) {
                    $categoryAverages[$criterion->criteria_name]['sum_value'] += $result['value'];
                    $categoryAverages[$criterion->criteria_name]['sum_score'] += $result['score'];
                    $categoryAverages[$criterion->criteria_name]['count']++;
                }

                // Accumulate total score
                if ($result['weighted_score'] !== null) {
                    $totalWeightedScore += $result['weighted_score'];
                    $totalWeight += $criterion->getWeightage();
                }
            }

            $overallScore = $totalWeight > 0 ? ($totalWeightedScore / $totalWeight) : null;

            $stocksData[] = [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'description' => $stock->description,
                'is_shariah' => $stock->is_shariah ?? false,
                'market_cap' => $stock->market_cap,
                'scores' => $stockScores,
                'overall_score' => $overallScore ? round($overallScore, 2) : null,
            ];
        }

        // Calculate final averages
        $finalAverages = [];
        foreach ($categoryAverages as $criterionName => $data) {
            $finalAverages[$criterionName] = [
                'avg_value' => $data['count'] > 0 ? round($data['sum_value'] / $data['count'], 2) : null,
                'avg_score' => $data['count'] > 0 ? round($data['sum_score'] / $data['count'], 2) : null,
            ];
        }

        return [
            'category_name' => $categoryName,
            'criteria' => array_column($criteriaDetails, 'name'),
            'criteria_details' => $criteriaDetails,
            'data' => $stocksData,
            'category_averages' => $finalAverages,
        ];
    }
}
