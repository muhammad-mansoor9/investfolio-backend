<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SectorPEService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
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
            $mansoorSpecial = filter_var($request->get('mansoor_special', false), FILTER_VALIDATE_BOOLEAN);

            // Get sector PE metrics using shared service
            $sectorPE = SectorPEService::calculateAll($calculationDate);

            // Get stock data with filters applied only to display
            $stocksData = $this->getStocksWithFairValue(
                $calculationDate,
                $sectorPE,
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
            Log::error('Fair value calculation error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate fair values. Please try again.',
                'error_detail' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate sector P/E metrics:
     * - sector_pe: Average PE of ALL active companies (excluding ttm_eps=0)
     * - sector_top_pe: Average PE of top 6 companies by market cap
     *
     * @param string $calculationDate
     * @return array
     */
    private function calculateSectorPE($calculationDate): array
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
                s.id,
                s.symbol,
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
                'stocks_count' => $row->stocks_count,
            ];
        }

        // Query 2: Calculate sector_top_pe (Top 6 by market cap)
        $query = "
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
                (pd.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY t6.id ORDER BY pd.period_end_date DESC) as rn
            FROM top_6_filtered t6
            LEFT JOIN period_dates pd ON t6.id = pd.stock_id
                AND pd.period_type = 'quarterly'
                AND pd.period_end_date <= :calc_date::date
                AND pd.data->>'eps' IS NOT NULL
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
                t6.description,
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
            WHERE te.ttm_eps > 0
        )
        SELECT
            sector_id,
            id as stock_id,
            symbol,
            description,
            latest_price,
            ttm_eps,
            pe_ratio
        FROM pe_ratios
        ORDER BY sector_id, latest_price DESC
        ";

        $individualResults = DB::select($query, [
            'calc_date' => $calculationDate,
        ]);

        // Aggregate individual stock PE to sector levels
        $sectorStats = [];

        foreach ($individualResults as $row) {
            if (!isset($sectorStats[$row->sector_id])) {
                $sectorStats[$row->sector_id] = [
                    'pe_values' => [],
                    'valid_count' => 0,
                    'stock_count' => 0,
                ];
            }

            $sectorStats[$row->sector_id]['stock_count']++;

            if ($row->pe_ratio !== null) {
                $sectorStats[$row->sector_id]['pe_values'][] = $row->pe_ratio;
                $sectorStats[$row->sector_id]['valid_count']++;
            }
        }

        // Calculate final sector_top_pe (top 6 average)
        $sectorPEs = [];

        foreach ($sectorStats as $sectorId => $stats) {
            $sectorTopPe = count($stats['pe_values']) > 0 ? round(array_sum($stats['pe_values']) / count($stats['pe_values']), 2) : null;

            $sectorPEs[$sectorId] = [
                'sector_pe' => $sectorPeMap[$sectorId]['sector_pe'] ?? null,
                'sector_top_pe' => $sectorTopPe,
                'top_6_count' => $stats['stock_count'],
                'top_6_valid_count' => $stats['valid_count'],
                'all_stocks_count' => $sectorPeMap[$sectorId]['stocks_count'] ?? 0,
                'min_pe_top_6' => count($stats['pe_values']) > 0 ? round(min($stats['pe_values']), 2) : null,
                'max_pe_top_6' => count($stats['pe_values']) > 0 ? round(max($stats['pe_values']), 2) : null,
            ];
        }

        return $sectorPEs;
    }

    /**
     * Get stocks with fair value calculations
     *
     * @param string $calculationDate
     * @param array $sectorAveragePE
     * @param bool $mansoorSpecial
     * @return array
     */
    private function getStocksWithFairValue(
        $calculationDate,
        $sectorAveragePE,
        $mansoorSpecial
    ): array {

        $query = "
        WITH period_dates AS (
            -- Parse period_name to get period end date (most reliable for historical data)
            -- Converts Mar '26 to 2026-03-31, Dec '25 to 2025-12-31, etc.
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
        latest_prices AS (
            SELECT
                s.id as stock_id,
                sp.close as current_price,
                sp.date as price_date
            FROM stocks s
            LEFT JOIN LATERAL (
                SELECT close, date FROM stock_prices
                WHERE stock_id = s.id AND date <= :calc_date::date
                ORDER BY date DESC LIMIT 1
            ) sp ON true
            WHERE s.is_active = true AND s.market_cap > 0
        ),
        latest_eps_value AS (
            -- Get the most recent single EPS value (not TTM, not average)
            SELECT
                pd.stock_id,
                (pd.data->>'eps')::numeric as current_eps,
                pd.period_end_date as eps_date,
                ROW_NUMBER() OVER (PARTITION BY pd.stock_id ORDER BY pd.period_end_date DESC) as rn
            FROM period_dates pd
            WHERE pd.period_end_date <= :calc_date::date
              AND pd.data->>'eps' IS NOT NULL
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
                pd.stock_id,
                (pd.data->>'eps')::numeric as eps_value,
                ROW_NUMBER() OVER (PARTITION BY pd.stock_id ORDER BY pd.period_end_date DESC) as rn
            FROM period_dates pd
            WHERE pd.period_type = 'quarterly'
              AND pd.period_end_date <= :calc_date::date
              AND pd.data->>'eps' IS NOT NULL
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
                pd.stock_id,
                (pd.data->>'eps')::numeric as quarterly_eps,
                pd.period_name,
                pd.period_end_date,
                ROW_NUMBER() OVER (PARTITION BY pd.stock_id ORDER BY pd.period_end_date DESC) as rn
            FROM period_dates pd
            WHERE pd.period_type = 'quarterly'
              AND pd.period_end_date <= :calc_date::date
              AND pd.data->>'eps' IS NOT NULL
        ),
        quarterly_average AS (
            SELECT
                stock_id,
                ROUND(AVG(quarterly_eps)::numeric, 2) as quarterly_eps_avg,
                json_agg(quarterly_eps ORDER BY rn) as last_3_eps,
                COALESCE(SUM(quarterly_eps), 0) as last_3_quarterly_eps_sum,
                MAX(period_end_date) as latest_result_date
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
        )
        SELECT
            stock_id,
            symbol,
            company_name,
            sector_id,
            sector_name,
            market_cap,
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
        $sectorPEDetails = [];

        foreach ($results as $row) {
            $sector_pe = $sectorAveragePE[$row->sector_id]['sector_pe'] ?? null;
            $sector_top_pe = $sectorAveragePE[$row->sector_id]['sector_top_pe'] ?? null;

            // Calculate TTM PE for logging
            $ttm_pe_calc = null;
            if ($row->ttm_eps > 0 && $row->current_price > 0) {
                $ttm_pe_calc = round($row->current_price / $row->ttm_eps, 2);
            }

            // Collect sector PE details for logging
            if (!isset($sectorPEDetails[$row->sector_id])) {
                $sectorPEDetails[$row->sector_id] = [
                    'sector_name' => $row->sector_name,
                    'stocks' => [],
                ];
            }

            // Track DNCC and DCL specifically
            if (in_array($row->symbol, ['DNCC', 'DCL'])) {
                Log::warning("SECTOR_PE_CALC: {$row->symbol}", [
                    'symbol' => $row->symbol,
                    'sector_id' => $row->sector_id,
                    'current_price' => $row->current_price,
                    'ttm_eps' => $row->ttm_eps,
                    'ttm_pe' => $ttm_pe_calc,
                    'sector_pe' => $sector_pe,
                    'has_ttm' => $row->ttm_eps != 0,
                    'has_price' => $row->current_price > 0,
                ]);
            }

            $sectorPEDetails[$row->sector_id]['stocks'][] = [
                'symbol' => $row->symbol,
                'ttm_eps' => $row->ttm_eps,
                'current_price' => $row->current_price,
                'ttm_pe' => $ttm_pe_calc,
            ];

            // Fair value calculations based on sector_pe (all inclusive)
            // Use absolute value of TTM EPS so unprofitable companies still get valuations
            $fair_price_current = null;
            $fair_value_gap_pct = null;
            if ($sector_pe !== null && $sector_pe > 0 && $row->ttm_eps != 0) {
                $abs_ttm_eps = abs($row->ttm_eps);
                $fair_price_current = round($sector_pe * $abs_ttm_eps, 2);
                $fair_value_gap_pct = $fair_price_current > 0
                    ? round(((($fair_price_current - $row->current_price) / $fair_price_current) * 100), 2)
                    : null;
            }

            $expected_fair_price_next_q = null;
            $expected_fair_value_gap_pct = null;
            if ($sector_pe !== null && $sector_pe > 0 && $row->quarterly_eps_avg !== null && $row->quarterly_eps_avg != 0) {
                // Annualize quarterly avg EPS (multiply by 4 to get annual run-rate)
                // Use absolute value of quarterly EPS so unprofitable companies still get valuations
                $annualized_quarterly_eps = abs($row->quarterly_eps_avg) * 4;
                $expected_fair_price_next_q = round($sector_pe * $annualized_quarterly_eps, 2);
                $expected_fair_value_gap_pct = $expected_fair_price_next_q > 0
                    ? round(((($expected_fair_price_next_q - $row->current_price) / $expected_fair_price_next_q) * 100), 2)
                    : null;
            }

            // Apply mansoor special filter (display only)
            if ($mansoorSpecial && !$this->passesMansoorCriteria($row)) {
                continue;
            }

            // Parse last_3_eps JSON once
            $lastThreeEpsValues = [];
            if ($row->last_3_eps) {
                $decoded = json_decode($row->last_3_eps, true);
                if ($decoded !== null) {
                    $lastThreeEpsValues = array_map('floatval', $decoded);
                } else {
                    Log::warning("Invalid JSON for last_3_eps: {$row->symbol}", ['json' => $row->last_3_eps]);
                }
            }

            // Calculate TTM PE (same as current_pe since current_pe uses ttm_eps)
            $ttm_pe = null;
            if ($row->ttm_eps != 0 && $row->current_price > 0) {
                $ttm_pe = round($row->current_price / $row->ttm_eps, 2);
            }

            $processedResults[] = [
                'stock_id' => $row->stock_id,
                'symbol' => $row->symbol,
                'company_name' => $row->company_name,
                'sector_id' => $row->sector_id,
                'sector_name' => $row->sector_name,
                'sector_pe' => $sector_pe,
                'sector_top_pe' => $sector_top_pe,
                'market_cap' => $row->market_cap,
                'current_price' => (float) $row->current_price,
                'current_eps' => $row->current_eps !== null ? (float) $row->current_eps : null,
                'ttm_eps' => $row->ttm_eps !== null ? (float) $row->ttm_eps : null,
                'current_pe' => $row->current_pe,
                'ttm_pe' => $ttm_pe,
                'fair_price_current' => $fair_price_current,
                'fair_value_gap_pct' => $fair_value_gap_pct,
                'last_3_quarterly_eps' => $lastThreeEpsValues,
                'quarterly_eps_avg' => $row->quarterly_eps_avg !== null ? (float) $row->quarterly_eps_avg : null,
                'expected_fair_price_next_q' => $expected_fair_price_next_q,
                'expected_fair_value_gap_pct' => $expected_fair_value_gap_pct,
                'latest_result_date' => $row->latest_result_date,
                'data_freshness_days' => $row->data_freshness_days,
            ];
        }

        // Log sector PE calculation details
        foreach ($sectorPEDetails as $sectorId => $details) {
            $validTTMCount = count(array_filter($details['stocks'], fn($s) => $s['ttm_pe'] !== null));
            Log::info("SECTOR_PE: {$details['sector_name']}", [
                'sector_id' => $sectorId,
                'total_stocks' => count($details['stocks']),
                'stocks_with_ttm_pe' => $validTTMCount,
                'sector_pe' => $sectorAveragePE[$sectorId]['sector_pe'] ?? null,
                'stocks' => $details['stocks'],
            ]);
        }

        // Debug DNCC and DCL data
        $dnccDclQuery = "
        SELECT
            fr.stock_id,
            s.symbol,
            fr.period_type,
            fr.period_name,
            fr.data->>'eps' as eps_value,
            fr.created_at
        FROM financial_results fr
        JOIN stocks s ON fr.stock_id = s.id
        WHERE s.symbol IN ('DNCC', 'DCL')
        ORDER BY s.symbol, fr.period_name DESC
        LIMIT 20
        ";
        $dnccDclData = DB::select($dnccDclQuery);
        if (count($dnccDclData) > 0) {
            Log::warning('DNCC_DCL_DEBUG: Financial Results Data', [
                'records_found' => count($dnccDclData),
                'data' => array_map(fn($r) => [
                    'symbol' => $r->symbol,
                    'period_type' => $r->period_type,
                    'period_name' => $r->period_name,
                    'eps_value' => $r->eps_value,
                ], $dnccDclData),
            ]);
        } else {
            Log::warning('DNCC_DCL_DEBUG: No financial_results data found');
        }

        return $processedResults;
    }

    /**
     * Check if stock passes Mansoor special criteria
     *
     * TODO: Implement actual Mansoor special criteria logic based on business rules
     * Current placeholder: Not yet implemented. Throws exception if requested.
     *
     * @param object $stock
     * @return bool
     * @throws \BadMethodCallException
     */
    private function passesMansoorCriteria($stock): bool
    {
        throw new \BadMethodCallException(
            'Mansoor special criteria filter is not yet implemented. ' .
            'Please define the business rules and implement the logic in ' .
            self::class . '::' . __FUNCTION__
        );
    }
}
