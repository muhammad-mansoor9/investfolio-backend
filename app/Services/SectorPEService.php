<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SectorPEService
{
    /**
     * Single source of truth for per-stock TTM EPS + latest price + market
     * cap + volume, as of a given date.
     *
     * Every PE consumer (this service, and PEAnalysisController) should
     * build on top of this instead of re-writing the TTM EPS / latest price
     * CTEs — that duplication is exactly what let a stale "eps > 0" filter
     * survive in one place after being fixed in another.
     *
     * TTM EPS rules:
     * - The latest 4 quarters are selected by parsing the `quarter` label
     *   (e.g. "Sep '25"), NOT by announcement/created_at date.
     * - Ranking happens over ALL quarters (positive and negative) before
     *   picking the latest 4 — filtering positive-only before ranking can
     *   silently swap out a real negative quarter for an older positive one.
     * - Negative quarters and negative TTM EPS are preserved. Callers decide
     *   whether/how to use loss-making stocks (e.g. PE ratio is undefined
     *   for negative EPS, but the weighted sector PE below must still
     *   include them to match broker-style aggregation).
     *
     * Only active stocks with a positive market cap are returned. A stock
     * with no financial_results rows at all comes back with ttm_eps = null
     * — callers must decide how to treat that (currently: excluded from
     * weighted sector PE, since there's nothing to weight).
     *
     * @param string $calculationDate
     * @return Collection<int, object{
     *     id: string, symbol: string, description: ?string, sector_id: string,
     *     market_cap: float, latest_price: ?float, latest_volume: ?float,
     *     ttm_eps: ?float
     * }>
     */
    public static function stockTtmData(string $calculationDate): Collection
    {
        $query = "
        WITH quarterly_ranked AS (
            SELECT
                fr.stock_id,
                fr.eps,
                ROW_NUMBER() OVER (
                    PARTITION BY fr.stock_id
                    ORDER BY
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
                ) AS rn
            FROM financial_results fr
            WHERE fr.eps IS NOT NULL
            -- Do NOT filter fr.eps > 0 here: ranking must run over every
            -- quarter so the true latest 4 are picked before summing.
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
                sp.close as latest_price,
                sp.volume as latest_volume
            FROM stocks s
            LEFT JOIN LATERAL (
                SELECT close, volume FROM stock_prices
                WHERE stock_id = s.id AND date <= :calc_date::date
                ORDER BY date DESC LIMIT 1
            ) sp ON true
            WHERE s.is_active = true AND s.market_cap > 0
        )
        SELECT
            s.id,
            s.symbol,
            s.description,
            s.sector_id,
            s.market_cap,
            lp.latest_price,
            lp.latest_volume,
            ttm.ttm_eps
        FROM stocks s
        INNER JOIN latest_prices lp ON s.id = lp.stock_id
        LEFT JOIN ttm_eps_data ttm ON s.id = ttm.stock_id
        WHERE s.is_active = true
          AND s.market_cap > 0
        ";

        $rows = DB::select($query, ['calc_date' => $calculationDate]);

        return collect($rows)->map(function ($row) {
            $row->market_cap = (float) $row->market_cap;
            $row->latest_price = $row->latest_price !== null ? (float) $row->latest_price : null;
            $row->latest_volume = $row->latest_volume !== null ? (float) $row->latest_volume : null;
            $row->ttm_eps = $row->ttm_eps !== null ? (float) $row->ttm_eps : null;

            return $row;
        });
    }

    /**
     * Calculate weighted sector PE metrics for all sectors.
     *
     * Weighted formula:
     *
     *     Sector PE = SUM(market_cap) / SUM((market_cap * ttm_eps) / latest_price)
     *
     * This weights larger companies more heavily and matches broker-style
     * aggregate sector PE, where loss-making companies pull the sector's
     * aggregate earnings down (and therefore the sector PE up), instead of
     * simply being dropped from the average. Verified against a live
     * broker sector page (Fertilizer, incl. a loss-making constituent):
     * this formula reproduces the broker's published sector PE.
     *
     * Returns:
     * - sector_pe: Weighted PE of ALL eligible active stocks
     * - sector_top_pe: Weighted PE of the top 6 by market cap
     *
     * "Eligible" = active, positive market cap, valid price, and a known
     * TTM EPS (positive or negative). Stocks with no financial_results at
     * all (ttm_eps === null) are excluded — there's nothing to weight.
     *
     * @param string $calculationDate
     * @return array Keyed by sector_id with sector_pe and sector_top_pe
     */
    public static function calculateAll(string $calculationDate): array
    {
        $stocks = self::stockTtmData($calculationDate);

        $eligible = $stocks->filter(
            fn ($s) => $s->latest_price > 0 && $s->ttm_eps !== null
        );

        $sectorPeMap = [];

        // sector_pe: every eligible stock in the sector.
        foreach ($eligible->groupBy('sector_id') as $sectorId => $group) {
            $sectorPeMap[$sectorId] = [
                'sector_pe' => self::weightedPe($group),
                'all_stocks_count' => $group->count(),
            ];
        }

        // sector_top_pe: top 6 by market cap, ranked among ALL active stocks
        // in the sector (not just the ones with usable EPS data) — matching
        // how a broker's "top constituents" list is built. Only the
        // eligible members of that top 6 contribute to the weighted PE.
        foreach ($stocks->groupBy('sector_id') as $sectorId => $group) {
            $top6 = $group->sortByDesc('market_cap')->take(6);
            $top6Eligible = $top6->filter(
                fn ($s) => $s->latest_price > 0 && $s->ttm_eps !== null
            );

            if (!isset($sectorPeMap[$sectorId])) {
                $sectorPeMap[$sectorId] = [
                    'sector_pe' => null,
                    'all_stocks_count' => 0,
                ];
            }

            $sectorPeMap[$sectorId]['sector_top_pe'] = self::weightedPe($top6Eligible);
            $sectorPeMap[$sectorId]['top_6_count'] = $top6Eligible->count();
        }

        return $sectorPeMap;
    }

    /**
     * Market-cap-weighted PE for a collection of stocks:
     * SUM(market_cap) / SUM(market_cap * ttm_eps / latest_price)
     * Returns null when aggregate weighted earnings are <= 0 (PE not
     * meaningful) or the collection is empty.
     *
     * @param Collection $stocks
     * @return float|null
     */
    private static function weightedPe(Collection $stocks): ?float
    {
        if ($stocks->isEmpty()) {
            return null;
        }

        $totalMarketCap = $stocks->sum('market_cap');
        $totalWeightedEarnings = $stocks->sum(
            fn ($s) => ($s->market_cap * $s->ttm_eps) / $s->latest_price
        );

        return $totalWeightedEarnings > 0
            ? round($totalMarketCap / $totalWeightedEarnings, 2)
            : null;
    }
}
