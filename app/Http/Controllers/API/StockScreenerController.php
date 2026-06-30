<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\StockScreenerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stock Screener Controller
 *
 * Handles stock screening and filtering operations
 */
class StockScreenerController extends Controller
{
    private StockScreenerService $screenerService;

    public function __construct(StockScreenerService $screenerService)
    {
        $this->screenerService = $screenerService;
    }

    /**
     * Get screener data for a specific sector
     *
     * GET /api/screener/data?sector_id=xxx
     *
     * Returns:
     * - sector: Basic sector info
     * - stocks: Array of stocks with all their ratios + latest price
     * - sector_averages: Average values for each ratio
     */
    public function getData(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'sector_id' => 'required|uuid|exists:sectors,id'
            ]);

            $sectorId = $validated['sector_id'];

            Log::info('Fetching screener data', ['sector_id' => $sectorId]);

            // Get data from service
            $rawData = $this->screenerService->getScreenerData($sectorId);

            // Transform to clean flat structure with latest prices
            $data = $this->transformData($rawData);

            Log::info('Screener data fetched successfully', [
                'sector' => $data['sector']['name'],
                'stocks_count' => count($data['stocks'])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Screener data retrieved successfully',
                'data' => $data
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to fetch screener data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve screener data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Transform nested service data to clean flat structure
     *
     * Input: Complex nested structure with stocks repeated per category
     * Output: Simple structure with each stock appearing once + latest price
     */
    private function transformData(array $rawData): array
    {
        $stocksById = [];
        $sectorAverages = [];

        // Process each category from service response
        foreach ($rawData['categories'] as $category) {
            // Collect sector averages
            foreach ($category['sector_average'] as $ratioName => $value) {
                $sectorAverages[$ratioName] = $value;
            }

            // Merge stock data
            foreach ($category['data'] as $stockData) {
                $stockId = $stockData['stock_id'];

                // Initialize stock on first encounter
                if (!isset($stocksById[$stockId])) {
                    $stocksById[$stockId] = [
                        'id' => $stockId,
                        'symbol' => $stockData['symbol'],
                        'name' => $stockData['description'],
                        'is_shariah' => $stockData['is_shariah'] ?? false,
                        'market_cap' => $stockData['market_cap'] ?? null,
                        'latest_price' => null, // Will be populated below
                        'ratios' => []
                    ];
                }

                // Merge ratios from this category
                $stocksById[$stockId]['ratios'] = array_merge(
                    $stocksById[$stockId]['ratios'],
                    $stockData['ratios']
                );
            }
        }

        // Load latest prices for all stocks
        $stockIds = array_keys($stocksById);
        $latestPrices = $this->getLatestPrices($stockIds);

        // Add latest prices to stocks
        foreach ($stocksById as $stockId => &$stockData) {
            if (isset($latestPrices[$stockId])) {
                $stockData['latest_price'] = $latestPrices[$stockId];
            }
        }

        // Convert to indexed array and sort alphabetically by symbol
        $stocks = array_values($stocksById);
        usort($stocks, fn($a, $b) => strcmp($a['symbol'], $b['symbol']));

        return [
            'sector' => $rawData['sector'],
            'stocks' => $stocks,
            'sector_averages' => $sectorAverages,
            'metadata' => [
                'total_stocks' => count($stocks),
                'timestamp' => now()->toIso8601String()
            ]
        ];
    }

    /**
     * Get latest prices for multiple stocks efficiently
     *
     * @param array $stockIds
     * @return array [stock_id => price_data]
     */
    private function getLatestPrices(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        // Get latest price for each stock in one query
        $prices = \DB::table('stock_prices')
            ->select('stock_id', 'close', 'date', 'change')
            ->whereIn('stock_id', $stockIds)
            ->whereIn('id', function ($query) use ($stockIds) {
                $query->select(\DB::raw('MAX(id)'))
                    ->from('stock_prices')
                    ->whereIn('stock_id', $stockIds)
                    ->groupBy('stock_id');
            })
            ->get();

        // Map by stock_id
        $priceMap = [];
        foreach ($prices as $price) {
            $priceMap[$price->stock_id] = [
                'price' => $price->close,
                'date' => $price->date,
                'change' => $price->change ?? null,
            ];
        }

        return $priceMap;
    }
}
