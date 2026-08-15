<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\StockScreenerService;
use App\Services\StockPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StockScreenerController extends Controller
{
    private const KEY_LATEST_PRICE = 'latest_price';
    private const KEY_RATIOS = 'ratios';
    private const KEY_SYMBOL = 'symbol';
    private const KEY_ID = 'id';
    private const KEY_SECTOR_AVERAGE = 'sector_average';

    private StockScreenerService $screenerService;
    private StockPriceService $priceService;

    public function __construct(
        StockScreenerService $screenerService,
        StockPriceService $priceService
    ) {
        $this->screenerService = $screenerService;
        $this->priceService = $priceService;
    }

    public function getData(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'sector_id' => 'required|uuid|exists:sectors,id'
            ]);

            $sectorId = $validated['sector_id'];
            Log::info('Fetching screener data', ['sector_id' => $sectorId]);

            $rawData = $this->screenerService->getScreenerData($sectorId);
            $data = $this->transformData($rawData);

            Log::info('Screener data fetched successfully', [
                'sector' => $data['sector']['name'],
                'stocks_count' => count($data['stocks'])
            ]);

            $sanitizedData = $this->sanitizeData($data);

            return response()->json([
                'success' => true,
                'message' => 'Screener data retrieved successfully',
                'data' => $sanitizedData
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sector not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Screener data fetch failed', [
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

    private function transformData(array $rawData): array
    {
        $stocksById = [];
        $sectorAverages = [];

        foreach ($rawData['categories'] as $category) {
            foreach ($category[self::KEY_SECTOR_AVERAGE] as $ratioName => $value) {
                $sectorAverages[$ratioName] = $value;
            }

            foreach ($category['data'] as $stockData) {
                $stockId = $stockData['stock_id'];

                if (!isset($stocksById[$stockId])) {
                    $stocksById[$stockId] = [
                        self::KEY_ID => $stockId,
                        self::KEY_SYMBOL => $stockData[self::KEY_SYMBOL],
                        'name' => $stockData['description'],
                        'is_shariah' => $stockData['is_shariah'] ?? false,
                        'market_cap' => $stockData['market_cap'] ?? null,
                        self::KEY_LATEST_PRICE => null,
                        self::KEY_RATIOS => []
                    ];
                }

                $stocksById[$stockId][self::KEY_RATIOS] = array_merge(
                    $stocksById[$stockId][self::KEY_RATIOS],
                    $stockData[self::KEY_RATIOS]
                );
            }
        }

        $stockIds = array_keys($stocksById);
        $latestPrices = $this->getLatestPrices($stockIds);

        foreach ($stocksById as $stockId => &$stockData) {
            if (isset($latestPrices[$stockId])) {
                $stockData[self::KEY_LATEST_PRICE] = $latestPrices[$stockId];
            }
        }

        $stocks = array_values($stocksById);

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

    private function getLatestPrices(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        $prices = \DB::table('stock_prices as sp')
            ->select('sp.stock_id', 'sp.close', 'sp.date', 'sp.change')
            ->whereIn('sp.stock_id', $stockIds)
            ->whereRaw('sp.date = (SELECT MAX(date) FROM stock_prices sp2 WHERE sp2.stock_id = sp.stock_id)')
            ->get();

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

    private function sanitizeData(array $data): array
    {
        return array_map(function($item) {
            if (is_array($item)) {
                return $this->sanitizeData($item);
            }
            if (is_float($item)) {
                if (is_infinite($item) || is_nan($item)) {
                    return null;
                }
            }
            return $item;
        }, $data);
    }
}
