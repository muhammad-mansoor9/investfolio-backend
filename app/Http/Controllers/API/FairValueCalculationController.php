<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class FairValueCalculationController extends Controller
{
    /**
     * Get fair value calculation for stocks based on sector P/E
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFairValueCalculation(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'nullable|date_format:Y-m-d',
                'shariah_only' => 'nullable|in:true,false,1,0',
                'mansoor_special' => 'nullable|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'data' => $validator->errors(),
                ], 422);
            }

            $calculationDate = $request->get('date', now()->toDateString());
            $shariahOnly = filter_var($request->get('shariah_only', false), FILTER_VALIDATE_BOOLEAN);
            $mansoorSpecial = filter_var($request->get('mansoor_special', false), FILTER_VALIDATE_BOOLEAN);

            // Get sector averages from ALL active companies (unfiltered)
            $sectorAveragePE = $this->calculateSectorAveragePE($calculationDate);

            // Get stock data with filters applied only to display
            $stocksData = $this->getStocksWithFairValue(
                $calculationDate,
                $sectorAveragePE,
                $shariahOnly,
                $mansoorSpecial
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'total_results' => count($stocksData),
                    'data' => $stocksData,
                ],
                'message' => 'Fair value calculation completed',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while calculating fair values',
                'data' => ['error' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Calculate sector average P/E from top 6 companies by market cap (all active companies)
     *
     * @param string $calculationDate
     * @return array
     */
    private function calculateSectorAveragePE($calculationDate): array
    {
        $query = "
        WITH sector_top_6 AS (
            -- Get top 6 companies per sector by market cap (ALL active companies, no filters)
            SELECT
                s.sector_id,
                s.id,
                s.symbol,
                s.description,
                s.market_cap,
                ROW_NUMBER() OVER (PARTITION BY s.sector_id ORDER BY s.market_cap DESC NULLS LAST) as cap_rank
            FROM stocks s
            WHERE s.is_active = true
              AND s.market_cap > 0
        ),
        top_6_filtered AS (
            SELECT *
            FROM sector_top_6
            WHERE cap_rank <= 6
        ),
        quarterly_ranked_top_6 AS (
            SELECT
                t6.id,
                (fr.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY t6.id ORDER BY fr.created_at DESC) as rn
            FROM top_6_filtered t6
            LEFT JOIN financial_results fr ON t6.id = fr.stock_id
                AND fr.period_type = 'quarterly'
                AND fr.created_at <= :calc_date::date
                AND fr.data->>'eps' IS NOT NULL
        ),
        ttm_eps_top_6 AS (
            -- Calculate TTM EPS as sum of last 4 quarterly eps from financial_results
            SELECT
                t6.id,
                t6.symbol,
                t6.sector_id,
                COALESCE(SUM(qr.eps_value), 0) as ttm_eps
            FROM top_6_filtered t6
            LEFT JOIN quarterly_ranked_top_6 qr ON t6.id = qr.id AND qr.rn <= 4
            GROUP BY t6.id, t6.symbol, t6.sector_id
        ),
        latest_prices AS (
            -- Get latest price for each of the top 6 stocks
            SELECT
                t6.id,
                sp.close as latest_price
            FROM top_6_filtered t6
            LEFT JOIN stock_prices sp ON t6.id = sp.stock_id
                AND sp.date = (SELECT MAX(date) FROM stock_prices sp2 WHERE sp2.stock_id = sp.stock_id AND sp2.date <= :calc_date::date)
        ),
        pe_ratios AS (
            SELECT
                t6.sector_id,
                t6.id,
                t6.symbol,
                COALESCE(lp.latest_price, 0) as latest_price,
                te.ttm_eps,
                CASE
                    WHEN COALESCE(lp.latest_price, 0) > 0 AND te.ttm_eps > 0
                        THEN ROUND((lp.latest_price / te.ttm_eps)::numeric, 2)
                    ELSE NULL
                END as pe_ratio
            FROM top_6_filtered t6
            LEFT JOIN latest_prices lp ON t6.id = lp.id
            LEFT JOIN ttm_eps_top_6 te ON t6.id = te.id
        )
        SELECT
            sector_id,
            ROUND(AVG(pe_ratio)::numeric, 2) as avg_pe,
            COUNT(DISTINCT id) as stocks_count,
            COUNT(DISTINCT CASE WHEN pe_ratio IS NOT NULL THEN id END) as valid_pe_count,
            MIN(pe_ratio) as min_pe,
            MAX(pe_ratio) as max_pe
        FROM pe_ratios
        GROUP BY sector_id
        ";

        $results = DB::select($query, [
            'calc_date' => $calculationDate,
        ]);

        $sectorPEs = [];
        foreach ($results as $result) {
            $sectorPEs[$result->sector_id] = [
                'avg_pe' => $result->avg_pe,
                'stocks_count' => $result->stocks_count,
                'valid_pe_count' => $result->valid_pe_count,
                'min_pe' => $result->min_pe,
                'max_pe' => $result->max_pe,
            ];
        }

        return $sectorPEs;
    }

    /**
     * Get stocks with fair value calculations, filtered by user preferences
     *
     * @param string $calculationDate
     * @param array $sectorAveragePE
     * @param bool $shariahOnly
     * @param bool $mansoorSpecial
     * @return array
     */
    private function getStocksWithFairValue(
        $calculationDate,
        $sectorAveragePE,
        $shariahOnly,
        $mansoorSpecial
    ): array {
        // Build filter conditions for DISPLAY only (not affecting PE calculation)
        $shariahCondition = $shariahOnly ? 'AND s.is_shariah = true' : '';

        $query = "
        WITH latest_prices AS (
            SELECT
                sp.stock_id,
                sp.close as current_price,
                sp.date as price_date
            FROM stock_prices sp
            WHERE sp.date = (SELECT MAX(date) FROM stock_prices sp2 WHERE sp2.stock_id = sp.stock_id AND sp2.date <= :calc_date::date)
        ),
        latest_eps_value AS (
            -- Get the most recent single EPS value (not TTM, not average)
            SELECT
                fr.stock_id,
                (fr.data->>'eps')::numeric as current_eps,
                fr.created_at as eps_date,
                ROW_NUMBER() OVER (PARTITION BY fr.stock_id ORDER BY fr.created_at DESC) as rn
            FROM financial_results fr
            WHERE fr.created_at <= :calc_date::date
              AND fr.data->>'eps' IS NOT NULL
        ),
        latest_eps_filtered AS (
            SELECT
                stock_id,
                current_eps,
                eps_date
            FROM latest_eps_value
            WHERE rn = 1
        ),
        quarterly_ranked AS (
            SELECT
                fr.stock_id,
                (fr.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY fr.stock_id ORDER BY fr.created_at DESC) as rn
            FROM financial_results fr
            WHERE fr.period_type = 'quarterly'
              AND fr.created_at <= :calc_date::date
              AND fr.data->>'eps' IS NOT NULL
        ),
        ttm_eps_data AS (
            -- Calculate TTM EPS: sum of latest 4 quarterly eps
            -- Only calculate if we have at least 2 quarters of data (4 quarters ideal, but be lenient)
            SELECT
                stock_id,
                CASE
                    WHEN COUNT(*) >= 2 THEN SUM(eps_value)
                    ELSE NULL
                END as ttm_eps
            FROM quarterly_ranked
            WHERE rn <= 4
            GROUP BY stock_id
        ),
        last_3_quarterly AS (
            -- Get last 3 quarterly EPS values
            SELECT
                fr.stock_id,
                (fr.data->>'eps')::numeric as quarterly_eps,
                fr.period_name,
                fr.created_at,
                ROW_NUMBER() OVER (PARTITION BY fr.stock_id ORDER BY fr.created_at DESC) as rn
            FROM financial_results fr
            WHERE fr.period_type = 'quarterly'
              AND fr.created_at <= :calc_date::date
              AND fr.data->>'eps' IS NOT NULL
        ),
        quarterly_average AS (
            SELECT
                stock_id,
                ROUND(AVG(quarterly_eps)::numeric, 2) as quarterly_eps_avg,
                json_agg(quarterly_eps ORDER BY rn) as last_3_eps,
                COALESCE(SUM(quarterly_eps), 0) as last_3_quarterly_eps_sum,
                MAX(created_at) as latest_result_date
            FROM last_3_quarterly
            WHERE rn <= 3
            GROUP BY stock_id
        ),
        stock_fair_values AS (
            SELECT
                s.id as stock_id,
                s.symbol,
                s.description as company_name,
                s.sector_id,
                sec.name as sector_name,
                s.market_cap,
                s.year_ending,
                lp.current_price,
                COALESCE(ttm.ttm_eps, 0) as ttm_eps,
                CASE
                    WHEN COALESCE(ttm.ttm_eps, 0) > 0 THEN ROUND((lp.current_price / ttm.ttm_eps)::numeric, 2)
                    ELSE NULL
                END as current_pe,
                leps.current_eps,
                qa.quarterly_eps_avg,
                qa.last_3_eps,
                qa.last_3_quarterly_eps_sum,
                qa.latest_result_date,
                CASE
                    WHEN qa.latest_result_date IS NOT NULL THEN
                        (CURRENT_DATE::date - qa.latest_result_date::date)
                    ELSE NULL
                END as data_freshness_days
            FROM stocks s
            LEFT JOIN sectors sec ON s.sector_id = sec.id
            LEFT JOIN latest_prices lp ON s.id = lp.stock_id
            LEFT JOIN latest_eps_filtered leps ON s.id = leps.stock_id
            LEFT JOIN ttm_eps_data ttm ON s.id = ttm.stock_id
            LEFT JOIN quarterly_average qa ON s.id = qa.stock_id
            WHERE s.is_active = true
              AND s.market_cap > 0
              $shariahCondition
        )
        SELECT
            stock_id,
            symbol,
            company_name,
            sector_id,
            sector_name,
            market_cap,
            year_ending,
            current_price,
            ttm_eps,
            current_pe,
            current_eps,
            quarterly_eps_avg,
            last_3_eps,
            last_3_quarterly_eps_sum,
            latest_result_date,
            data_freshness_days
        FROM stock_fair_values
        WHERE current_price IS NOT NULL
          AND current_price > 0
        ORDER BY sector_id, market_cap DESC
        ";

        $params = [
            'calc_date' => $calculationDate,
        ];

        $results = DB::select($query, $params);

        // Process results and add fair value calculations
        $processedResults = [];
        foreach ($results as $row) {
            $sector_avg_pe = $sectorAveragePE[$row->sector_id]['avg_pe'] ?? null;

            $fair_price_current = null;
            $fair_value_gap_pct = null;
            if ($sector_avg_pe !== null && $sector_avg_pe > 0 && $row->ttm_eps > 0) {
                $fair_price_current = round($sector_avg_pe * $row->ttm_eps, 2);
                $fair_value_gap_pct = $fair_price_current > 0
                    ? round(((($fair_price_current - $row->current_price) / $fair_price_current) * 100), 2)
                    : null;
            }

            $expected_fair_price_next_q = null;
            $expected_fair_value_gap_pct = null;
            if ($sector_avg_pe !== null && $sector_avg_pe > 0 && $row->quarterly_eps_avg && $row->quarterly_eps_avg > 0) {
                $expected_fair_price_next_q = round($sector_avg_pe * $row->quarterly_eps_avg, 2);
                $expected_fair_value_gap_pct = $expected_fair_price_next_q > 0
                    ? round(((($expected_fair_price_next_q - $row->current_price) / $expected_fair_price_next_q) * 100), 2)
                    : null;
            }

            // Apply mansoor special filter (display only)
            if ($mansoorSpecial && !$this->passesMansoorCriteria($row)) {
                continue;
            }

            $processedResults[] = [
                'stock_id' => $row->stock_id,
                'symbol' => $row->symbol,
                'company_name' => $row->company_name,
                'sector_id' => $row->sector_id,
                'sector_name' => $row->sector_name,
                'sector_avg_pe' => $sector_avg_pe,
                'market_cap' => $row->market_cap,
                'year_ending' => $row->year_ending,
                'current_price' => (float) $row->current_price,
                'current_eps' => (float) $row->current_eps ?: null,
                'ttm_eps' => (float) $row->ttm_eps ?: null,
                'ttm_pe' => $row->ttm_eps > 0 ? round(($row->current_price / $row->ttm_eps), 2) : null,
                'current_pe' => $row->current_pe,
                'fair_price_current' => $fair_price_current,
                'fair_value_gap_pct' => $fair_value_gap_pct,
                'last_3_quarterly_eps' => $row->last_3_eps ? array_map('floatval', (array) json_decode($row->last_3_eps, true)) : [],
                'quarterly_eps_avg' => (float) $row->quarterly_eps_avg ?: null,
                'expected_fair_price_next_q' => $expected_fair_price_next_q,
                'expected_fair_value_gap_pct' => $expected_fair_value_gap_pct,
                'latest_result_date' => $row->latest_result_date,
                'data_freshness_days' => $row->data_freshness_days,
            ];
        }

        return $processedResults;
    }

    /**
     * Check if stock passes Mansoor special criteria
     *
     * TODO: Implement actual Mansoor special criteria logic based on business rules
     *
     * @param object $stock
     * @return bool
     */
    private function passesMansoorCriteria($stock): bool
    {
        // Placeholder: implement actual Mansoor special criteria
        // For now, return true (all stocks pass)
        return true;
    }
}
