<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SectorPEService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

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
            Log::info('[FVC] Fair value calculation started', [
                'request_params' => $request->all(),
                'user_id' => auth()->id(),
            ]);

            $validator = Validator::make($request->all(), [
                'date' => 'nullable|date_format:Y-m-d',
                'mansoor_special' => 'nullable|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                Log::warning('[FVC] Validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'data' => $validator->errors(),
                ], 422);
            }

            $calculationDate = $request->get('date', now()->toDateString());
            $mansoorSpecial = filter_var($request->get('mansoor_special', false), FILTER_VALIDATE_BOOLEAN);
            Log::info('[FVC] Calculation parameters set', [
                'date' => $calculationDate,
                'mansoor_special' => $mansoorSpecial,
            ]);

            // Get sector PE metrics using weighted formula from service
            Log::info('[FVC] Fetching sector PE metrics from SectorPEService');
            $sectorPEMetrics = SectorPEService::calculateAll($calculationDate);
            Log::info('[FVC] Sector PE metrics fetched', [
                'sectors_count' => count($sectorPEMetrics),
                'sample_sectors' => array_slice($sectorPEMetrics, 0, 3),
            ]);

            $sectorPE = collect($sectorPEMetrics)->mapWithKeys(function ($metrics, $sectorId) {
                return [$sectorId => ['sector_pe' => $metrics['sector_pe']]];
            })->toArray();

            // Get stock data with filters applied only to display
            Log::info('[FVC] Fetching stocks with fair value calculations');
            $stocksData = $this->getStocksWithFairValue(
                $calculationDate,
                $sectorPE,
                $mansoorSpecial
            );

            Log::info('[FVC] Fair value calculation completed successfully', [
                'total_results' => count($stocksData),
                'sample_data' => array_slice($stocksData, 0, 2),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_results' => count($stocksData),
                    'data' => $stocksData,
                ],
                'message' => 'Fair value calculation completed',
            ], 200);

        } catch (\Exception $e) {
            Log::error('[FVC] Fair value calculation error', [
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
     * Get stocks with fair value calculations including:
     * - EPS Growth QOQ and YoY
     * - Latest 3Q EPS and 4Q Expected EPS
     * - Latest 3Q PE and 4Q Expected PE
     * - Fair Value based on Sector PE
     * - Growth Potential
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

        Log::info('[FVC::getStocksWithFairValue] Starting stock data fetch', [
            'calculation_date' => $calculationDate,
            'sector_count' => count($sectorAveragePE),
            'mansoor_special' => $mansoorSpecial,
        ]);

        $query = "
        WITH latest_3q AS (
            -- Get latest 3 quarters sorted by quarter (Mar, Jun, Sep, Dec)
            SELECT
                fr.stock_id,
                fr.eps,
                fr.quarter,
                fr.announcement_date,
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
        q3_metrics AS (
            -- Calculate 3Q metrics (Q1, Q2, Q3 = latest 3 quarters)
            SELECT
                stock_id,
                SUM(eps) as eps_3q_sum,
                ROUND(AVG(eps)::numeric, 2) as eps_avg,
                MAX(CASE WHEN rn = 1 THEN announcement_date END) as q3_announcement_date
            FROM latest_3q
            WHERE rn <= 3
            GROUP BY stock_id
        ),
        current_prices AS (
            SELECT
                s.id as stock_id,
                sp.close as current_price
            FROM stocks s
            LEFT JOIN LATERAL (
                SELECT close FROM stock_prices
                WHERE stock_id = s.id AND date <= :calc_date::date
                ORDER BY date DESC LIMIT 1
            ) sp ON true
            WHERE s.is_active = true AND s.market_cap > 0
        ),
        q3_prices AS (
            -- Get price on Q3 announcement date or latest available before it
            SELECT
                qm.stock_id,
                qm.eps_3q_sum,
                qm.eps_avg,
                qm.q3_announcement_date,
                (SELECT close FROM stock_prices
                 WHERE stock_id = qm.stock_id
                   AND date <= qm.q3_announcement_date
                 ORDER BY date DESC LIMIT 1) as price_at_q3_announcement
            FROM q3_metrics qm
        ),
        stock_with_eps AS (
            SELECT
                s.id as stock_id,
                s.symbol,
                s.description as company_name,
                s.is_shariah,
                s.sector_id,
                sec.name as sector_name,
                s.market_cap,
                cp.current_price,
                qp.eps_3q_sum,
                qp.eps_avg,
                qp.price_at_q3_announcement,
                qp.q3_announcement_date
            FROM stocks s
            LEFT JOIN sectors sec ON s.sector_id = sec.id
            LEFT JOIN current_prices cp ON s.id = cp.stock_id
            LEFT JOIN q3_prices qp ON s.id = qp.stock_id
            WHERE s.is_active = true AND s.market_cap > 0
        )
        SELECT
            stock_id,
            symbol,
            company_name,
            is_shariah,
            sector_id,
            sector_name,
            market_cap,
            current_price,
            eps_3q_sum,
            eps_avg,
            price_at_q3_announcement,
            q3_announcement_date
        FROM stock_with_eps
        WHERE current_price IS NOT NULL AND current_price > 0
            AND eps_3q_sum IS NOT NULL AND eps_3q_sum > 0
        ORDER BY sector_id, market_cap DESC
        ";

        Log::info('[FVC::getStocksWithFairValue] Executing database query');
        $results = DB::select($query, ['calc_date' => $calculationDate]);
        Log::info('[FVC::getStocksWithFairValue] Query executed', [
            'results_count' => count($results),
            'sample_row' => isset($results[0]) ? (array) $results[0] : null,
        ]);

        $processedResults = [];
        $skipped_count = 0;

        foreach ($results as $row) {
            Log::debug('[FVC::getStocksWithFairValue] Processing stock', [
                'symbol' => $row->symbol,
                'sector_id' => $row->sector_id,
                'current_price' => $row->current_price,
                'eps_3q_sum' => $row->eps_3q_sum,
                'eps_avg' => $row->eps_avg,
                'price_at_q3' => $row->price_at_q3_announcement,
            ]);

            $sector_pe = $sectorAveragePE[$row->sector_id]['sector_pe'] ?? null;
            if ($sector_pe === null || $sector_pe <= 0) {
                Log::debug('[FVC::getStocksWithFairValue] Skipped: No sector PE', [
                    'symbol' => $row->symbol,
                    'sector_id' => $row->sector_id,
                ]);
                $skipped_count++;
                continue;
            }

            if ($mansoorSpecial && !$this->passesMansoorCriteria($row)) {
                Log::debug('[FVC::getStocksWithFairValue] Skipped: Mansoor criteria not met', [
                    'symbol' => $row->symbol,
                ]);
                $skipped_count++;
                continue;
            }

            $current_price = (float) $row->current_price;
            $eps_3q_sum = (float) $row->eps_3q_sum;
            $eps_avg = (float) $row->eps_avg;
            $price_at_q3 = $row->price_at_q3_announcement ? (float) $row->price_at_q3_announcement : null;

            // 3Q PE = Price at Q3 announcement / Sum of last 3Q EPS
            $latest_3q_pe = null;
            if ($price_at_q3 !== null && $price_at_q3 > 0 && $eps_3q_sum > 0) {
                $latest_3q_pe = round($price_at_q3 / $eps_3q_sum, 2);
                Log::debug('[FVC::calc] 3Q PE calculated', [
                    'symbol' => $row->symbol,
                    'price_at_q3' => $price_at_q3,
                    'eps_3q_sum' => $eps_3q_sum,
                    'latest_3q_pe' => $latest_3q_pe,
                ]);
            } else {
                Log::debug('[FVC::calc] 3Q PE skipped', [
                    'symbol' => $row->symbol,
                    'price_at_q3' => $price_at_q3,
                    'eps_3q_sum' => $eps_3q_sum,
                ]);
            }

            // Expected Annual EPS = Average of 3Q × 4
            $expected_annual_eps = round($eps_avg * 4, 2);
            Log::debug('[FVC::calc] Expected annual EPS', [
                'symbol' => $row->symbol,
                'eps_avg' => $eps_avg,
                'expected_annual_eps' => $expected_annual_eps,
            ]);

            // Expected PE = Current Price / Expected Annual EPS
            $expected_pe = null;
            if ($expected_annual_eps > 0 && $current_price > 0) {
                $expected_pe = round($current_price / $expected_annual_eps, 2);
                Log::debug('[FVC::calc] Expected PE calculated', [
                    'symbol' => $row->symbol,
                    'current_price' => $current_price,
                    'expected_annual_eps' => $expected_annual_eps,
                    'expected_pe' => $expected_pe,
                ]);
            }

            // Fair Value = Sector PE × Expected Annual EPS
            $fair_value = null;
            if ($sector_pe > 0 && $expected_annual_eps > 0) {
                $fair_value = round($sector_pe * $expected_annual_eps, 2);
                Log::debug('[FVC::calc] Fair value calculated', [
                    'symbol' => $row->symbol,
                    'sector_pe' => $sector_pe,
                    'expected_annual_eps' => $expected_annual_eps,
                    'fair_value' => $fair_value,
                ]);
            }

            // Growth Potential = (Fair Value - CMP) / CMP × 100
            $growth_potential = null;
            if ($fair_value !== null && $fair_value > 0 && $current_price > 0) {
                $growth_potential = round((($fair_value - $current_price) / $current_price) * 100, 2);
                Log::debug('[FVC::calc] Growth potential calculated', [
                    'symbol' => $row->symbol,
                    'fair_value' => $fair_value,
                    'current_price' => $current_price,
                    'growth_potential' => $growth_potential,
                ]);
            }

            // Calculate additional fields for frontend
            $expected_quarterly_eps = $expected_annual_eps > 0 ? round($expected_annual_eps / 4, 2) : null;

            $processedResults[] = [
                'stock_id' => (string) $row->stock_id,
                'symbol' => (string) $row->symbol,
                'company_name' => (string) $row->company_name,
                'is_shariah' => (bool) $row->is_shariah,
                'sector_id' => (string) $row->sector_id,
                'sector_name' => (string) $row->sector_name,
                'cmp' => $current_price,
                'sector_pe' => (float) $sector_pe,
                'latest_3q_eps' => $eps_3q_sum,
                'expected_quarterly_eps' => $expected_quarterly_eps,
                'expected_annual_eps' => $expected_annual_eps,
                'latest_3q_pe' => $latest_3q_pe,
                'annual_expected_pe' => $expected_pe,
                'fair_value' => $fair_value,
                'growth_potential' => $growth_potential,
                'latest_result_date' => $row->q3_announcement_date ? (string) $row->q3_announcement_date : null,
            ];
        }

        Log::info('[FVC::getStocksWithFairValue] Processing complete', [
            'total_rows' => count($results),
            'processed' => count($processedResults),
            'skipped' => $skipped_count,
        ]);

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
