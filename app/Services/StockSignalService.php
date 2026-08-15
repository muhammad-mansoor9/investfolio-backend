<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class StockSignalService
{
    public function cacheAllSignals($tradingDate)
    {
        // Get all unique strategies
        $strategies = DB::table('stock_signals')
            ->distinct()
            ->pluck('strategy');

        foreach ($strategies as $strategy) {
            // For each strategy, get the latest signals
            $signals = DB::table('stock_signals as ss')
                ->join('stocks as s', 'ss.stock_id', '=', 's.id')
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                ->select([
                    'ss.stock_id',
                    's.symbol',
                    's.description as company_name',
                    's.is_shariah',
                    'sec.id as sector_id',
                    'sec.name as sector_name',
                    'ss.strategy',
                    'ss.signal_name',
                    'ss.signal_state',
                    'ss.signal_value',
                    'ss.metadata',
                    'ss.signal_date',
                ])
                ->where('ss.strategy', $strategy)
                ->where('ss.signal_date', '<=', $tradingDate)
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0)
                ->get();

            if ($signals->isNotEmpty()) {
                $cacheKey = "psx:stock_signals:{$strategy}:{$tradingDate}";
                Cache::put(
                    $cacheKey,
                    $signals->toArray(),
                    CacheService::CACHE_TTL_DAILY
                );
            }
        }

        return true;
    }

    /**
     * Get cached signals for a strategy
     */
    public function getSignalsByStrategy($strategy, $tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:stock_signals:{$strategy}:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: stock signals', [
                'source' => 'cache',
                'strategy' => $strategy,
                'date' => $date,
                'signal_count' => count($cached),
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: stock signals, no cached data', [
            'source' => 'cache_empty',
            'strategy' => $strategy,
            'date' => $date,
        ]);
        return [];
    }

    /**
     * Get all cached signals for a date
     */
    public function getAllSignals($tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');

        $strategies = ['explosive', 'swing', 'positional'];
        $allSignals = [];

        foreach ($strategies as $strategy) {
            $signals = $this->getSignalsByStrategy($strategy, $date);
            if (!empty($signals)) {
                $allSignals[$strategy] = $signals;
            }
        }

        return $allSignals;
    }
}
