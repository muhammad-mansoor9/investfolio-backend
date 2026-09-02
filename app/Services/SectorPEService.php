<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SectorPEService
{
    /**
     * Calculate weighted sector PE metrics for all sectors
     *
     * Weighted formula: Sector PE = SUM(market_cap) / SUM((market_cap * ttm_eps) / latest_price)
     * This weights larger companies more heavily in the sector average.
     *
     * Returns:
     * - sector_pe: Weighted PE of ALL active stocks
     * - sector_top_pe: Weighted PE of top 6 by market cap
     *
     * @param string $calculationDate
     * @return array Keyed by sector_id with sector_pe and sector_top_pe
     */
    public static function calculateAll(string $calculationDate): array
    {
        // Query 1: Calculate weighted sector_pe (ALL active stocks)
        $allStocksQuery = "
        WITH quarterly_ranked AS (
            SELECT
                fr.stock_id,
                fr.eps,
                ROW_NUMBER() OVER (PARTITION BY fr.stock_id ORDER BY
                    make_date(
                        2000 + CAST(SUBSTRING(fr.quarter FROM '[0-9]+') AS INT),
                        CASE SUBSTRING(fr.quarter, 1, 3)
                            WHEN 'Mar' THEN 3
                            WHEN 'Jun' THEN 6
                            WHEN 'Sep' THEN 9
                            WHEN 'Dec' THEN 12
                        END,
                        1
                    ) DESC
                ) as rn
            FROM financial_results fr
            WHERE fr.eps IS NOT NULL AND fr.eps > 0
        ),
        ttm_eps_data AS (
            SELECT
                stock_id,
                SUM(eps) as ttm_eps
            FROM quarterly_ranked
            WHERE rn <= 4
            GROUP BY stock_id
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
        stock_data_with_ttm AS (
            SELECT
                s.sector_id,
                s.id,
                s.symbol,
                s.market_cap,
                COALESCE(lp.latest_price, 0) as latest_price,
                COALESCE(ttm.ttm_eps, 0) as ttm_eps
            FROM stocks s
            LEFT JOIN latest_prices lp ON s.id = lp.stock_id
            LEFT JOIN ttm_eps_data ttm ON s.id = ttm.stock_id
            WHERE s.is_active = true AND s.market_cap > 0
              AND COALESCE(lp.latest_price, 0) > 0
              AND COALESCE(ttm.ttm_eps, 0) > 0
        ),
        sector_pe_weighted AS (
            SELECT
                sector_id,
                CASE
                    WHEN SUM((market_cap::numeric * ttm_eps::numeric) / latest_price::numeric) > 0 THEN
                        ROUND((SUM(market_cap::numeric) / SUM((market_cap::numeric * ttm_eps::numeric) / latest_price::numeric))::numeric, 2)
                    ELSE NULL
                END as sector_pe,
                COUNT(*) as stocks_count
            FROM stock_data_with_ttm
            GROUP BY sector_id
        )
        SELECT
            sector_id,
            sector_pe,
            stocks_count
        FROM sector_pe_weighted
        ";

        $allStocksResults = DB::select($allStocksQuery, [
            'calc_date' => $calculationDate,
        ]);

        $sectorPeMap = [];
        foreach ($allStocksResults as $row) {
            $sectorPeMap[$row->sector_id] = [
                'sector_pe' => (float) $row->sector_pe,
                'all_stocks_count' => (int) $row->stocks_count,
            ];
        }

        // Query 2: Calculate weighted sector_top_pe (Top 6 by market cap)
        $topSixQuery = "
        WITH sector_top_6 AS (
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
                fr.eps,
                ROW_NUMBER() OVER (PARTITION BY t6.id ORDER BY
                    make_date(
                        2000 + CAST(SUBSTRING(fr.quarter FROM '[0-9]+') AS INT),
                        CASE SUBSTRING(fr.quarter, 1, 3)
                            WHEN 'Mar' THEN 3
                            WHEN 'Jun' THEN 6
                            WHEN 'Sep' THEN 9
                            WHEN 'Dec' THEN 12
                        END,
                        1
                    ) DESC
                ) as rn
            FROM top_6_filtered t6
            LEFT JOIN financial_results fr ON t6.id = fr.stock_id
                AND fr.eps IS NOT NULL
                AND fr.eps > 0
        ),
        ttm_eps_top_6 AS (
            SELECT
                t6.id,
                t6.sector_id,
                COALESCE(SUM(qr.eps), 0) as ttm_eps
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
        top_6_with_data AS (
            SELECT
                t6.sector_id,
                t6.market_cap,
                COALESCE(lp.latest_price, 0) as latest_price,
                te.ttm_eps
            FROM top_6_filtered t6
            LEFT JOIN latest_prices lp ON t6.id = lp.stock_id
            LEFT JOIN ttm_eps_top_6 te ON t6.id = te.id
            WHERE te.ttm_eps > 0
        ),
        sector_top_pe_weighted AS (
            SELECT
                sector_id,
                CASE
                    WHEN SUM((market_cap::numeric * ttm_eps::numeric) / latest_price::numeric) > 0 THEN
                        ROUND((SUM(market_cap::numeric) / SUM((market_cap::numeric * ttm_eps::numeric) / latest_price::numeric))::numeric, 2)
                    ELSE NULL
                END as sector_top_pe,
                COUNT(*) as stocks_count
            FROM top_6_with_data
            GROUP BY sector_id
        )
        SELECT
            sector_id,
            sector_top_pe,
            stocks_count
        FROM sector_top_pe_weighted
        ";

        $topSixResults = DB::select($topSixQuery, [
            'calc_date' => $calculationDate,
        ]);

        foreach ($topSixResults as $row) {
            if (isset($sectorPeMap[$row->sector_id])) {
                $sectorPeMap[$row->sector_id]['sector_top_pe'] = (float) $row->sector_top_pe;
                $sectorPeMap[$row->sector_id]['top_6_count'] = (int) $row->stocks_count;
            }
        }

        return $sectorPeMap;
    }
}
