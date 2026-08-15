<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StockIndicatorService
{
    public function cacheAllIndicators($tradingDate)
    {
        // Get all stock indicators from the database
        $indicators = DB::table('stock_indicators')
            ->select('stock_id', 'timeframe', 'data')
            ->get();

        foreach ($indicators as $indicator) {
            $cacheKey = "psx:stock_indicators:{$indicator->stock_id}:{$indicator->timeframe}:{$tradingDate}";

            // Cache with 3-day TTL (daily data)
            Cache::put(
                $cacheKey,
                json_decode($indicator->data, true),
                CacheService::CACHE_TTL_DAILY
            );
        }

        return true;
    }

    /**
     * Get cached indicators for a stock
     */
    public function getIndicators($stockId, $timeframe = '1D', $tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:stock_indicators:{$stockId}:{$timeframe}:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: stock indicators', [
                'source' => 'cache',
                'stock_id' => $stockId,
                'timeframe' => $timeframe,
                'date' => $date,
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: stock indicators, fetching from database', [
            'source' => 'database',
            'stock_id' => $stockId,
            'timeframe' => $timeframe,
            'date' => $date,
        ]);
        return $this->fetchFromDB($stockId, $timeframe);
    }

    /**
     * Fetch indicators from database
     */
    private function fetchFromDB($stockId, $timeframe = '1D')
    {
        $indicator = DB::table('stock_indicators')
            ->where('stock_id', $stockId)
            ->where('timeframe', $timeframe)
            ->first();

        return $indicator ? json_decode($indicator->data, true) : null;
    }
}
