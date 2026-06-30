<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FinancialRatio;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Financial Ratio Metadata Controller
 *
 * Provides ratio definitions, categories, and descriptions
 * Uses FinancialRatio model from financial_ratios table
 */
class FinancialRatioController extends Controller
{
    /**
     * Get all ratio metadata (categories, descriptions, etc.)
     *
     * GET /api/ratios/metadata
     *
     * Returns:
     * - categories: Organized list of ratios by category
     * - ratios: Complete ratio information with descriptions
     * - summary: Summary statistics
     *
     * Response is cached for 1 hour (3600 seconds)
     */
    public function getMetadata(): JsonResponse
    {
        try {
            // Cache for 1 hour since ratio definitions rarely change
            $metadata = Cache::remember('financial_ratio_metadata', 3600, function () {
                return $this->buildMetadata();
            });

            return response()->json([
                'success' => true,
                'message' => 'Ratio metadata retrieved successfully',
                'data' => $metadata
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch ratio metadata', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve ratio metadata',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clear metadata cache
     *
     * POST /api/ratios/clear-cache
     *
     * Useful when ratio definitions are updated
     */
    public function clearCache(): JsonResponse
    {
        try {
            Cache::forget('financial_ratio_metadata');

            return response()->json([
                'success' => true,
                'message' => 'Ratio metadata cache cleared successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to clear ratio metadata cache', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache'
            ], 500);
        }
    }

    /**
     * Get ratios for a specific category
     *
     * GET /api/ratios/category/{categoryName}
     */
    public function getCategoryRatios(string $categoryName): JsonResponse
    {
        try {
            $ratios = FinancialRatio::active()
                ->category($categoryName)
                ->orderByDisplayOrder()
                ->get();

            if ($ratios->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found or no active ratios'
                ], 404);
            }

            $ratioList = $ratios->map(function ($ratio) {
                return [
                    'name' => $ratio->ratio_name,
                    'description' => $ratio->ratio_description,
                    'category' => $ratio->ratio_category,
                    'is_critical' => $ratio->isCritical(),
                    'display_order' => $ratio->getDisplayOrder(),
                    'benchmark_range' => $ratio->getBenchmarkRange()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $categoryName,
                    'ratios' => $ratioList
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch category ratios', [
                'category' => $categoryName,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve category ratios'
            ], 500);
        }
    }

    /**
     * Get ratios for a specific sector (sector-specific + universal)
     *
     * GET /api/ratios/sector/{sectorId}
     */
    public function getSectorRatios(string $sectorId): JsonResponse
    {
        try {
            // Get universal ratios + sector-specific ratios
            $ratios = FinancialRatio::active()
                ->where(function ($query) use ($sectorId) {
                    $query->whereNull('sector_id') // Universal ratios
                    ->orWhere('sector_id', $sectorId); // Sector-specific
                })
                ->orderByDisplayOrder()
                ->get();

            $metadata = $this->formatRatios($ratios);

            return response()->json([
                'success' => true,
                'data' => $metadata
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch sector ratios', [
                'sector_id' => $sectorId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sector ratios'
            ], 500);
        }
    }

    /**
     * Build complete metadata structure
     */
    private function buildMetadata(): array
    {
        Log::info('Building ratio metadata');

        // Get all active universal ratios (sector_id is null)
        $ratios = FinancialRatio::active()
            ->universal()
            ->orderByDisplayOrder()
            ->get();

        return $this->formatRatios($ratios);
    }

    /**
     * Format ratios into the structure needed by frontend
     */
    private function formatRatios($ratios): array
    {
        $result = [
            'categories' => [],
            'ratios' => [],
            'summary' => [
                'total_categories' => 0,
                'total_ratios' => 0,
                'critical_ratios' => 0
            ]
        ];

        $categoriesSet = [];
        $totalRatios = 0;
        $criticalRatios = 0;

        foreach ($ratios as $ratio) {
            $categoryName = $ratio->ratio_category;
            $ratioName = $ratio->ratio_name;

            // Initialize category if not exists
            if (!isset($result['categories'][$categoryName])) {
                $result['categories'][$categoryName] = [];
                $categoriesSet[$categoryName] = true;
            }

            // Add ratio to category
            $result['categories'][$categoryName][] = $ratioName;

            // Add detailed ratio info
            $isCritical = $ratio->isCritical();

            $result['ratios'][$ratioName] = [
                'name' => $ratioName,
                'description' => $ratio->ratio_description,
                'category' => $categoryName,
                'is_critical' => $isCritical,
                'display_order' => $ratio->getDisplayOrder(),
                'benchmark_range' => $ratio->getBenchmarkRange()
            ];

            $totalRatios++;
            if ($isCritical) {
                $criticalRatios++;
            }
        }

        $result['summary'] = [
            'total_categories' => count($categoriesSet),
            'total_ratios' => $totalRatios,
            'critical_ratios' => $criticalRatios,
            'cached_at' => now()->toIso8601String()
        ];

        Log::info('Ratio metadata built successfully', [
            'categories' => count($categoriesSet),
            'ratios' => $totalRatios
        ]);

        return $result;
    }
}
