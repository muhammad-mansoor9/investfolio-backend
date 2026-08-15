<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SectorPEService
{
    /**
     * Calculate sector PE metrics for all sectors
     *
     * Returns:
     * - sector_pe: Average PE of ALL active stocks (excluding ttm_eps=0)
     * - sector_top_pe: Average PE of top 6 by market cap
     *
     * @param string $calculationDate
     * @return array Keyed by sector_id with sector_pe and sector_top_pe
     */
    public static function calculateAll(string $calculationDate): array
    {
        // Query 1: Calculate sector_pe (ALL active stocks, excluding ttm_eps=0)
        $allStocksQuery = "
        WITH period_dates AS (
            SELECT
                id,
                stock_id,
                period_type,
                period_name,
                CASE
                    WHEN period_name ~ '^\w+\s+''[0-9]{2}$' THEN
                        (DATE_TRUNC('month',
                            make_date(2000 + CAST(SUBSTRING(period_name FROM '[0-9]+') AS INT),
                            CASE SUBSTRING(period_name, 1, 3)
                                WHEN 'Mar' THEN 3
                                WHEN 'Jun' THEN 6
                                WHEN 'Sep' THEN 9
                                WHEN 'Dec' THEN 12
                                ELSE 12
                            END,
                            1)
                        ) + INTERVAL '1 month' - INTERVAL '1 day')::date
                    ELSE NULL
                END as period_end_date,
                data
            FROM financial_results
        ),
        all_quarterly_ranked AS (
            SELECT
                pd.stock_id,
                (pd.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY pd.stock_id ORDER BY pd.period_end_date DESC) as rn
            FROM period_dates pd
            WHERE pd.period_type = 'quarterly'
              AND pd.period_end_date <= :calc_date::date
              AND pd.data->>'eps' IS NOT NULL
        ),
        all_ttm_eps AS (
            SELECT
                stock_id,
                CASE
                    WHEN COUNT(*) >= 2 THEN SUM(eps_value)
                    ELSE NULL
                END as ttm_eps
            FROM all_quarterly_ranked
            WHERE rn <= 4
            GROUP BY stock_id
        ),
        all_latest_prices AS (
            SELECT
                s.id as stock_id,
                sp.close as latest_price
            FROM stocks s
            LEFT JOIN LATERAL (
                SELECT close FROM stock_prices
                WHERE stock_id = s.id AND date <= :calc_date::date
                ORDER BY date DESC LIMIT 1
            ) sp ON true
            WHERE s.is_active = true AND s.market_cap > 0
        ),
        all_pe_ratios AS (
            SELECT
                s.sector_id,
                COALESCE(alp.latest_price, 0) as latest_price,
                COALESCE(ate.ttm_eps, 0) as ttm_eps,
                CASE
                    WHEN COALESCE(alp.latest_price, 0) > 0 AND COALESCE(ate.ttm_eps, 0) > 0
                        THEN ROUND((alp.latest_price / ate.ttm_eps)::numeric, 2)
                    ELSE NULL
                END as pe_ratio
            FROM stocks s
            LEFT JOIN all_latest_prices alp ON s.id = alp.stock_id
            LEFT JOIN all_ttm_eps ate ON s.id = ate.stock_id
            WHERE s.is_active = true AND s.market_cap > 0
              AND COALESCE(alp.latest_price, 0) > 0
              AND COALESCE(ate.ttm_eps, 0) > 0
        )
        SELECT
            sector_id,
            ROUND(AVG(pe_ratio)::numeric, 2) as sector_pe,
            COUNT(*) as stocks_count
        FROM all_pe_ratios
        GROUP BY sector_id
        ";

        $allStocksResults = DB::select($allStocksQuery, [
            'calc_date' => $calculationDate,
        ]);

        $sectorPeMap = [];
        foreach ($allStocksResults as $row) {
            $sectorPeMap[$row->sector_id] = [
                'sector_pe' => $row->sector_pe,
                'all_stocks_count' => $row->stocks_count,
            ];
        }

        // Query 2: Calculate sector_top_pe (Top 6 by market cap)
        $topSixQuery = "
        WITH period_dates AS (
            SELECT
                id,
                stock_id,
                period_type,
                period_name,
                CASE
                    WHEN period_name ~ '^\w+\s+''[0-9]{2}$' THEN
                        (DATE_TRUNC('month',
                            make_date(2000 + CAST(SUBSTRING(period_name FROM '[0-9]+') AS INT),
                            CASE SUBSTRING(period_name, 1, 3)
                                WHEN 'Mar' THEN 3
                                WHEN 'Jun' THEN 6
                                WHEN 'Sep' THEN 9
                                WHEN 'Dec' THEN 12
                                ELSE 12
                            END,
                            1)
                        ) + INTERVAL '1 month' - INTERVAL '1 day')::date
                    ELSE NULL
                END as period_end_date,
                data
            FROM financial_results
        ),
        sector_top_6 AS (
            SELECT
                s.sector_id,
                s.id,
                s.symbol,
                s.market_cap,
                ROW_NUMBER() OVER (PARTITION BY s.sector_id ORDER BY s.market_cap DESC NULLS LAST) as cap_rank
            FROM stocks s
            WHERE s.is_active = true AND s.market_cap > 0
        ),
        top_6_filtered AS (
            SELECT * FROM sector_top_6 WHERE cap_rank <= 6
        ),
        quarterly_ranked_top_6 AS (
            SELECT
                t6.id,
                (pd.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY t6.id ORDER BY pd.period_end_date DESC) as rn
            FROM top_6_filtered t6
            LEFT JOIN period_dates pd ON t6.id = pd.stock_id
                AND pd.period_type = 'quarterly'
                AND pd.period_end_date <= :calc_date::date
                AND pd.data->>'eps' IS NOT NULL
        ),
        ttm_eps_top_6 AS (
            SELECT
                t6.id,
                t6.sector_id,
                COALESCE(SUM(qr.eps_value), 0) as ttm_eps
            FROM top_6_filtered t6
            LEFT JOIN quarterly_ranked_top_6 qr ON t6.id = qr.id AND qr.rn <= 4
            GROUP BY t6.id, t6.sector_id
        ),
        latest_prices AS (
            SELECT
                s.id as stock_id,
                sp.close as latest_price
            FROM stocks s
            LEFT JOIN LATERAL (
                SELECT close FROM stock_prices
                WHERE stock_id = s.id AND date <= :calc_date::date
                ORDER BY date DESC LIMIT 1
            ) sp ON true
            WHERE s.is_active = true AND s.market_cap > 0
        ),
        pe_ratios_top_6 AS (
            SELECT
                t6.sector_id,
                COALESCE(lp.latest_price, 0) as latest_price,
                te.ttm_eps,
                CASE
                    WHEN COALESCE(lp.latest_price, 0) > 0 AND te.ttm_eps > 0
                        THEN ROUND((lp.latest_price / te.ttm_eps)::numeric, 2)
                    ELSE NULL
                END as pe_ratio
            FROM top_6_filtered t6
            LEFT JOIN latest_prices lp ON t6.id = lp.stock_id
            LEFT JOIN ttm_eps_top_6 te ON t6.id = te.id
            WHERE te.ttm_eps > 0
        )
        SELECT
            sector_id,
            ROUND(AVG(pe_ratio)::numeric, 2) as sector_top_pe,
            COUNT(*) as stocks_count
        FROM pe_ratios_top_6
        GROUP BY sector_id
        ";

        $topSixResults = DB::select($topSixQuery, [
            'calc_date' => $calculationDate,
        ]);

        foreach ($topSixResults as $row) {
            if (isset($sectorPeMap[$row->sector_id])) {
                $sectorPeMap[$row->sector_id]['sector_top_pe'] = $row->sector_top_pe;
                $sectorPeMap[$row->sector_id]['top_6_count'] = $row->stocks_count;
            }
        }

        return $sectorPeMap;
    }
}
