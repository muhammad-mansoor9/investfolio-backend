<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UINSettlementService
{
    public function cacheAllSettlementData($tradingDate)
    {
        $settlementData = DB::table('uin_settlement_data')
            ->select('*')
            ->where('settlement_date', '<=', $tradingDate)
            ->get();

        if ($settlementData->isNotEmpty()) {
            $cacheKey = "psx:uin_settlement:{$tradingDate}";
            Cache::put(
                $cacheKey,
                $settlementData->toArray(),
                CacheService::CACHE_TTL_DAILY
            );
        }

        return true;
    }

    /**
     * Get cached settlement data
     */
    public function getSettlementData($tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');
        $cacheKey = "psx:uin_settlement:{$date}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: UIN settlement data', [
                'source' => 'cache',
                'date' => $date,
                'record_count' => count($cached),
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: UIN settlement data', [
            'source' => 'cache_empty',
            'date' => $date,
        ]);
        return [];
    }

    /**
     * Get settlement data for a specific stock
     */
    public function getSettlementDataByStock($stockId, $tradingDate = null)
    {
        $allData = $this->getSettlementData($tradingDate);

        return collect($allData)
            ->where('stock_id', $stockId)
            ->first();
    }
}
