<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class FIPILIPIService
{
    public function cacheTradingData($tradingDate)
    {
        $tradingData = DB::table('fipi_lipi_trading_data')
            ->select('*')
            ->where('trading_date', '<=', $tradingDate)
            ->get();

        if ($tradingData->isNotEmpty()) {
            $cacheKey = "psx:fipi_lipi_trading:{$tradingDate}";
            Cache::put(
                $cacheKey,
                $tradingData->toArray(),
                CacheService::CACHE_TTL_DAILY
            );
        }

        return true;
    }

    public function cacheMarketData($tradingDate)
    {
        $marketData = DB::table('fipi_lipi_market_data')
            ->select('*')
            ->where('trading_date', '<=', $tradingDate)
            ->get();

        if ($marketData->isNotEmpty()) {
            $cacheKey = "psx:fipi_lipi_market:{$tradingDate}";
            Cache::put(
                $cacheKey,
                $marketData->toArray(),
                CacheService::CACHE_TTL_DAILY
            );
        }

        return true;
    }

    /**
     * Get cached trading data
     */
    public function getTradingData($tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:fipi_lipi_trading:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: FIPI/LIPI trading data', [
                'source' => 'cache',
                'date' => $date,
                'record_count' => count($cached),
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: FIPI/LIPI trading data', [
            'source' => 'cache_empty',
            'date' => $date,
        ]);
        return [];
    }

    /**
     * Get cached market data
     */
    public function getMarketData($tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:fipi_lipi_market:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: FIPI/LIPI market data', [
                'source' => 'cache',
                'date' => $date,
                'record_count' => count($cached),
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: FIPI/LIPI market data', [
            'source' => 'cache_empty',
            'date' => $date,
        ]);
        return [];
    }
}
