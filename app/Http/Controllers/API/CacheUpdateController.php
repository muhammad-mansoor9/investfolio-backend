<?php

namespace App\Http\Controllers\API;

use App\Services\CacheService;
use App\Services\PECalculationService;
use App\Services\StockIndicatorService;
use App\Services\StockSignalService;
use App\Services\FIPILIPIService;
use App\Services\UINSettlementService;
use App\Services\StockService;
use App\Services\StockPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CacheUpdateController extends BaseController
{
    public function __construct(
        private PECalculationService $peService,
        private StockIndicatorService $indicatorService,
        private StockSignalService $signalService,
        private FIPILIPIService $fipiLipiService,
        private UINSettlementService $uinService,
        private StockService $stockService,
        private StockPriceService $priceService,
    ) {}

    /**
     * Update cache entries (for external jobs)
     * Called by third-party data jobs to populate cache
     */
    public function updateDailyCache(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'last_trading_date' => 'required|date_format:Y-m-d',
                'data_type' => 'sometimes|in:stocks,prices,indicators,signals,fipi_lipi,uin',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $tradingDate = $request->get('last_trading_date');
            $dataType = $request->get('data_type');

            // Cache stocks and sectors only when stocks data is updated (sync_stocks job)
            if ($dataType === 'stocks') {
                $sectors = \Illuminate\Support\Facades\DB::table('sectors')->pluck('name', 'id');
                \Illuminate\Support\Facades\Cache::forever('psx:sectors', $sectors);
                $this->stockService->cacheAllStocks();
            }

            $this->priceService->cachePricesByDate($tradingDate);
            $this->peService->cacheStockTTMPE($tradingDate);
            $this->indicatorService->cacheAllIndicators($tradingDate);
            $this->signalService->cacheAllSignals($tradingDate);
            $this->fipiLipiService->cacheTradingData($tradingDate);
            $this->fipiLipiService->cacheMarketData($tradingDate);
            $this->uinService->cacheAllSettlementData($tradingDate);

            // Set last_trading_date only when prices are updated (live_stock_price_scraper job)
            // last_trading_date = date of actual trading activity, not just stock metadata
            if ($dataType === 'prices' || !$dataType) {
                \Illuminate\Support\Facades\Cache::forever('psx:last_trading_date', $tradingDate);
            }

            return $this->successResponse([
                'last_trading_date' => $tradingDate,
                'cached_at' => now()->toIso8601String(),
                'status' => 'All caches updated (stocks, prices, stock TTM PE, indicators, signals, FIPI/LIPI, UIN settlement). Sector average PE is cached on-demand via GET /api/pe/sector/{sectorId}',
            ], 'Cache updated successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to update cache', $e);
        }
    }

    /**
     * Clear daily cache for a specific date
     * Useful for manual corrections
     */
    public function clearDailyCache(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'trading_date' => 'required|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $date = $request->get('trading_date');

            // Clear keys for the specific date
            $keysToForget = [
                "psx:sector_pe:*:{$date}",
                "psx:stock_ttm_pe:*:{$date}",
                "psx:stock_indicators:*:{$date}",
                "psx:stock_signals:*:{$date}",
                "psx:fipi_lipi_trading:{$date}",
                "psx:fipi_lipi_market:{$date}",
                "psx:uin_settlement:{$date}",
            ];

            foreach ($keysToForget as $key) {
                CacheService::forget($key);
            }

            return $this->successResponse(
                ['cleared_date' => $date],
                'Daily cache cleared successfully'
            );

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to clear cache', $e);
        }
    }

    /**
     * Get cache statistics and health
     */
    public function getCacheStats(): JsonResponse
    {
        try {
            return $this->successResponse([
                'last_trading_date' => \Illuminate\Support\Facades\Cache::get('psx:last_trading_date'),
                'cache_backend' => config('cache.default'),
                'message' => 'Cache is operational',
            ], 'Cache statistics retrieved');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to get cache stats', $e);
        }
    }
}
