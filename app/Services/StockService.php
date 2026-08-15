<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StockService
{
    public function cacheAllStocks()
    {
        $stocks = DB::table('stocks')
            ->select('*')
            ->where('is_active', true)
            ->get();

        if ($stocks->isNotEmpty()) {
            Cache::put(
                'psx:stocks:all',
                $stocks->toArray(),
                24 * 60 * 60
            );

            foreach ($stocks as $stock) {
                Cache::forever(
                    "psx:stock:{$stock->id}:{$stock->symbol}",
                    (array) $stock
                );
            }
        }

        return true;
    }

    /**
     * Get all cached stocks
     */
    public function getAllStocks()
    {
        $cached = \Illuminate\Support\Facades\Cache::get('psx:stocks:all');
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: all stocks', [
                'source' => 'cache',
                'stock_count' => count($cached),
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: all stocks, fetching from database', [
            'source' => 'database',
        ]);

        return Cache::remember('psx:stocks:all', 24 * 60 * 60, function () {
            return DB::table('stocks')
                ->where('is_active', true)
                ->get()
                ->toArray();
        });
    }

    /**
     * Get cached stock by ID
     */
    public function getStock($stockId)
    {
        $stock = DB::table('stocks')
            ->where('id', $stockId)
            ->first();

        if (!$stock) {
            return null;
        }

        $cacheKey = "psx:stock:{$stock->id}:{$stock->symbol}";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: stock by ID', [
                'source' => 'cache',
                'stock_id' => $stockId,
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: stock by ID', [
            'source' => 'database',
            'stock_id' => $stockId,
        ]);

        Cache::forever($cacheKey, (array) $stock);

        return $stock;
    }

    /**
     * Get cached stock by symbol
     */
    public function getStockBySymbol($symbol)
    {
        $stocks = $this->getAllStocks();

        return collect($stocks)
            ->firstWhere('symbol', $symbol);
    }
}
