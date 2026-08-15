<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PECalculationService
{
    public function getSectorPECached($sectorId)
    {
        $cacheKey = "psx:sector_pe_avg:{$sectorId}";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: sector average PE', [
                'source' => 'cache',
                'sector_id' => $sectorId,
            ]);
            return $cached;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: sector average PE, calculating', [
            'source' => 'calculation',
            'sector_id' => $sectorId,
        ]);

        $allSectors = $this->calculateSectorPE();
        $sectorData = collect($allSectors)->firstWhere('sector_id', $sectorId);

        if ($sectorData) {
            Cache::put($cacheKey, $sectorData, 24 * 60 * 60);
        }

        return $sectorData;
    }

    public function cacheStockTTMPE($tradingDate)
    {
        $stockPEData = $this->calculateStockPE();

        foreach ($stockPEData as $stock) {
            Cache::put(
                "psx:stock_ttm_pe:{$stock['symbol']}:{$tradingDate}",
                $stock,
                CacheService::CACHE_TTL_DAILY
            );

            Cache::forever(
                "psx:stock_ttm_pe_latest:{$stock['symbol']}",
                $stock
            );
        }

        return true;
    }

    /**
     * Get cached sector P/E or calculate fresh
     */
    public function getSectorPE($tradingDate = null)
    {
        // Calculate fresh (sectors aggregate dynamically)
        $sectorData = $this->calculateSectorPE();

        if (!empty($sectorData)) {
            // Log calculation
            \Illuminate\Support\Facades\Log::info('Sector P/E calculated', [
                'source' => 'calculation',
                'sector_count' => count($sectorData),
            ]);
        } else {
            \Illuminate\Support\Facades\Log::info('Cache miss: sector P/E, fetching from database', [
                'source' => 'database',
            ]);
        }

        return $sectorData;
    }

    /**
     * Get cached sector P/E by ID
     */
    public function getSectorPEById($sectorId, $tradingDate = null)
    {
        $date = $tradingDate ?? now()->format('Y-m-d');

        // Try daily cache first
        $cacheKey = "psx:sector_pe:{$sectorId}:{$date}";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            \Illuminate\Support\Facades\Log::info('Cache hit: sector P/E by ID', [
                'source' => 'cache',
                'sector_id' => $sectorId,
                'date' => $date,
            ]);
            return $cached;
        }

        // Try aggregate (no date)
        $aggregateKey = "psx:sector_pe_aggregate:{$sectorId}";
        $aggregate = Cache::get($aggregateKey);

        if ($aggregate) {
            \Illuminate\Support\Facades\Log::info('Cache hit: sector P/E aggregate', [
                'source' => 'cache',
                'sector_id' => $sectorId,
                'key_type' => 'aggregate',
            ]);
            return $aggregate;
        }

        \Illuminate\Support\Facades\Log::info('Cache miss: sector P/E, calculating', [
            'source' => 'calculation',
            'sector_id' => $sectorId,
        ]);

        // Calculate all and return this sector's data
        $allSectors = $this->calculateSectorPE();
        $sectorData = collect($allSectors)->firstWhere('sector_id', $sectorId);

        return $sectorData;
    }

    /**
     * Core sector P/E calculation logic
     */
    private function calculateSectorPE()
    {
        $maxPEThreshold = 30;

        $query = "
        WITH stock_pe_data AS (
            SELECT
                s.sector_id,
                CASE
                    WHEN fd.value::numeric > 0 THEN
                        (sp.close / fd.value::numeric)
                    ELSE NULL
                END as pe_ratio
            FROM stocks s
            INNER JOIN stock_prices sp ON s.id = sp.stock_id
                AND sp.date = (SELECT MAX(date) FROM stock_prices)
            INNER JOIN financial_data fd ON s.symbol = fd.symbol
            WHERE s.is_active = true
              AND s.market_cap > 0
              AND fd.type = 'ANNUAL'
              AND fd.identifier = 'EPS'
              AND fd.header = 'LTM'
              AND fd.table_name = 'Income Statement'
              AND fd.value IS NOT NULL
              AND fd.value::numeric > 0
              AND sp.close > 0
        )
        SELECT
            sec.id as sector_id,
            sec.name AS sector_name,
            ROUND(AVG(spd.pe_ratio)::numeric, 2) as avg_pe,
            ROUND(MIN(spd.pe_ratio)::numeric, 2) as min_pe,
            ROUND(MAX(spd.pe_ratio)::numeric, 2) as max_pe,
            ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY spd.pe_ratio)::numeric, 2) as median_pe,
            COUNT(*) as total_stocks
        FROM stock_pe_data spd
        INNER JOIN sectors sec ON sec.id = spd.sector_id
        WHERE spd.pe_ratio IS NOT NULL
          AND spd.pe_ratio > 0
        GROUP BY spd.sector_id, sec.id, sec.name
        HAVING COUNT(*) >= 3
        ORDER BY ROUND(AVG(spd.pe_ratio)::numeric, 2) ASC
        ";

        $results = DB::select($query);

        return collect($results)->map(function ($row) {
            return [
                'sector_id' => $row->sector_id,
                'sector_name' => $row->sector_name,
                'avg_pe' => (float) $row->avg_pe,
                'min_pe' => (float) $row->min_pe,
                'max_pe' => (float) $row->max_pe,
                'median_pe' => (float) $row->median_pe,
                'total_stocks' => (int) $row->total_stocks,
            ];
        })->toArray();
    }

    /**
     * Core stock P/E calculation logic (stocks with filters)
     */
    private function calculateStockPE($minFloat = 10, $maxFloat = 100, $shariahOnly = false)
    {
        $maxPEThreshold = 30;
        $shariahCondition = $shariahOnly ? 'AND s.is_shariah = true' : '';

        $query = "
        WITH all_stock_pe AS (
            SELECT
                s.sector_id,
                sp.close / NULLIF(fd.value::numeric, 0) as pe_ratio
            FROM stocks s
            INNER JOIN stock_prices sp ON s.id = sp.stock_id
                AND sp.date = (SELECT MAX(date) FROM stock_prices)
            INNER JOIN financial_data fd ON s.symbol = fd.symbol
            WHERE s.is_active = true
              AND s.market_cap > 0
              AND fd.type = 'ANNUAL'
              AND fd.identifier = 'EPS'
              AND fd.header = 'LTM'
              AND fd.table_name = 'Income Statement'
              AND fd.value IS NOT NULL
              AND fd.value::numeric > 0
              AND sp.close > 0
              AND (sp.close / fd.value::numeric) > 0
              AND (sp.close / fd.value::numeric) <= :max_pe_threshold
        ),
        eligible_stocks AS (
            SELECT
                s.id,
                s.symbol,
                s.description,
                s.sector_id,
                ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_pct
            FROM stocks s
            INNER JOIN stock_prices sp_latest ON s.id = sp_latest.stock_id
                AND sp_latest.date = (SELECT MAX(date) FROM stock_prices)
            WHERE s.is_active = true
              AND s.market_cap > 0
              $shariahCondition
              AND s.total_shares_outstanding > 0
              AND s.free_float > 0
              AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100)
                  BETWEEN :min_free_float AND :max_free_float
        ),
        latest_prices AS (
            SELECT
                sp.stock_id,
                sp.close as latest_price,
                sp.volume as latest_volume
            FROM stock_prices sp
            INNER JOIN eligible_stocks es ON sp.stock_id = es.id
            WHERE sp.date = (SELECT MAX(date) FROM stock_prices)
        ),
        ttm_eps AS (
            SELECT
                fd.symbol,
                fd.value::numeric as ttm_eps_value
            FROM financial_data fd
            INNER JOIN eligible_stocks es ON fd.symbol = es.symbol
            WHERE fd.type = 'ANNUAL'
              AND fd.identifier = 'EPS'
              AND fd.header = 'LTM'
              AND fd.table_name = 'Income Statement'
              AND fd.value IS NOT NULL
              AND fd.value::numeric > 0
        )
        SELECT
            es.id,
            es.symbol,
            es.description,
            es.sector_id,
            es.free_float_pct,
            lp.latest_price,
            lp.latest_volume,
            te.ttm_eps_value,
            CASE
                WHEN te.ttm_eps_value > 0 THEN
                    ROUND((lp.latest_price / te.ttm_eps_value)::numeric, 2)
                ELSE NULL
            END as pe_ratio
        FROM eligible_stocks es
        INNER JOIN latest_prices lp ON es.id = lp.stock_id
        INNER JOIN ttm_eps te ON es.symbol = te.symbol
        WHERE te.ttm_eps_value > 0
          AND lp.latest_price > 0
        ";

        $results = DB::select($query, [
            'min_free_float' => $minFloat,
            'max_free_float' => $maxFloat,
            'max_pe_threshold' => $maxPEThreshold,
        ]);

        return collect($results)->map(function ($row) {
            return [
                'stock_id' => $row->id,
                'symbol' => $row->symbol,
                'description' => $row->description,
                'sector_id' => $row->sector_id,
                'free_float_pct' => (float) $row->free_float_pct,
                'current_price' => (float) $row->latest_price,
                'ltm_eps' => (float) $row->ttm_eps_value,
                'pe_ratio' => (float) $row->pe_ratio,
            ];
        })->toArray();
    }
}
