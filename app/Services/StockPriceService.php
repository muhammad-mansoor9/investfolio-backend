<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StockPriceService
{
    public function cachePricesByDate(string $tradingDate): bool
    {
        $prices = DB::table('stock_prices')
            ->join('stocks', 'stock_prices.stock_id', '=', 'stocks.id')
            ->select('stock_prices.stock_id', 'stocks.symbol', 'stock_prices.open', 'stock_prices.high', 'stock_prices.low', 'stock_prices.close', 'stock_prices.volume', 'stock_prices.date')
            ->where('stock_prices.date', $tradingDate)
            ->get();

        foreach ($prices as $price) {
            Cache::put(
                "psx:prices:{$price->symbol}:{$tradingDate}",
                (array) $price,
                CacheService::CACHE_TTL_DAILY
            );

            Cache::put(
                "psx:prices_latest:{$price->symbol}",
                (array) $price,
                86400
            );
        }

        return true;
    }

    public function getPriceBySymbolAndDate(string $symbol, ?string $tradingDate = null): ?array
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:prices:{$symbol}:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            Log::debug('Cache hit for stock price', ['symbol' => $symbol, 'date' => $date]);
            return $cached;
        }

        Log::debug('Cache miss for stock price, querying database', ['symbol' => $symbol, 'date' => $date]);

        $price = DB::table('stock_prices')
            ->join('stocks', 'stock_prices.stock_id', '=', 'stocks.id')
            ->where('stocks.symbol', $symbol)
            ->where('stock_prices.date', $date)
            ->first();

        if ($price) {
            Cache::put($cacheKey, (array) $price, CacheService::CACHE_TTL_DAILY);
        }

        return $price ? (array) $price : null;
    }

    public function getLatestPrice(string $symbol): ?array
    {
        $cacheKey = "psx:prices_latest:{$symbol}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            Log::debug('Cache hit for latest price', ['symbol' => $symbol]);
            return $cached;
        }

        Log::debug('Cache miss for latest price, querying database', ['symbol' => $symbol]);

        $price = DB::table('stock_prices')
            ->join('stocks', 'stock_prices.stock_id', '=', 'stocks.id')
            ->where('stocks.symbol', $symbol)
            ->orderBy('stock_prices.date', 'desc')
            ->first();

        if ($price) {
            Cache::put($cacheKey, (array) $price, 86400);
            return (array) $price;
        }

        return null;
    }

    public function getPricesByDate(?string $tradingDate = null): array
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:prices_all:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            Log::debug('Cache hit for all prices', ['date' => $date, 'count' => count($cached)]);
            return $cached;
        }

        Log::debug('Cache miss for all prices, querying database', ['date' => $date]);

        $prices = DB::table('stock_prices')
            ->where('date', $date)
            ->get()
            ->toArray();

        if (!empty($prices)) {
            Cache::put($cacheKey, $prices, CacheService::CACHE_TTL_DAILY);
        }

        return $prices;
    }
}
