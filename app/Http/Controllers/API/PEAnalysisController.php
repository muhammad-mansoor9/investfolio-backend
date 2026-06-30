<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PEAnalysisController extends Controller
{
    /**
     * Get P/E analysis for stocks or sectors
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPEAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:stocks,sectors',
                'min_float' => 'nullable|numeric|min:0',
                'max_float' => 'nullable|numeric|min:0',
                'shariah_only' => 'nullable|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->get('type');
            $minFloat = $request->get('min_float', 10);
            $maxFloat = $request->get('max_float', 100);
            $shariahOnly = filter_var($request->get('shariah_only', false), FILTER_VALIDATE_BOOLEAN);

            // Validate min < max
            if ($minFloat >= $maxFloat) {
                return $this->validationErrorResponse(['min_float' => 'Min float must be less than max float']);
            }

            if ($type === 'stocks') {
                return $this->getStocksPEAnalysis($minFloat, $maxFloat, $shariahOnly);
            } else {
                return $this->getSectorsPEAnalysis($minFloat, $maxFloat, $shariahOnly);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving P/E analysis', $e);
        }
    }

    /**
     * Get P/E analysis for stocks
     */
    private function getStocksPEAnalysis($minFloat, $maxFloat, $shariahOnly): JsonResponse
    {
        // Max P/E threshold for reasonable valuations
        $maxPEThreshold = 30;

        // Build shariah condition for filtered stocks only
        $shariahCondition = $shariahOnly ? 'AND s.is_shariah = true' : '';

        // Main query - sector average calculated from ALL stocks, but results filtered by user preferences
        $query = "
        WITH all_stock_pe AS (
            -- Calculate P/E for ALL stocks to get true sector averages
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
        sector_avg_pe AS (
            -- Sector average from ALL stocks
            SELECT
                sector_id,
                AVG(pe_ratio) as avg_sector_pe,
                COUNT(*) as stocks_in_sector
            FROM all_stock_pe
            GROUP BY sector_id
            HAVING COUNT(*) >= 3
        ),
        eligible_stocks AS (
            -- User's filtered stocks (by free float and shariah)
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
        ),
        stock_pe_data AS (
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
        )
        SELECT
            ROW_NUMBER() OVER (
                ORDER BY
                    ((sap.avg_sector_pe - spd.pe_ratio) / NULLIF(sap.avg_sector_pe, 0)) DESC,
                    spd.pe_ratio ASC
            ) AS rank,
            spd.id as stock_id,
            spd.symbol,
            spd.description,
            sec.id as sector_id,
            sec.name as sector_name,
            spd.latest_price,
            spd.ttm_eps_value,
            spd.pe_ratio,
            ROUND(sap.avg_sector_pe::numeric, 2) as sector_avg_pe,
            ROUND((spd.pe_ratio / NULLIF(sap.avg_sector_pe, 0))::numeric, 2) as pe_vs_sector,
            ROUND((((sap.avg_sector_pe - spd.pe_ratio) / NULLIF(sap.avg_sector_pe, 0)) * 100)::numeric, 2) as discount_pct,
            spd.latest_volume,
            spd.free_float_pct,
            sap.stocks_in_sector
        FROM stock_pe_data spd
        INNER JOIN sector_avg_pe sap ON spd.sector_id = sap.sector_id
        INNER JOIN sectors sec ON sec.id = spd.sector_id
        WHERE spd.pe_ratio IS NOT NULL
          AND spd.pe_ratio > 0
          AND spd.pe_ratio <= :max_pe_threshold
          AND spd.pe_ratio < sap.avg_sector_pe
          AND sap.avg_sector_pe > 0
        ORDER BY rank
        ";

        try {
            $results = DB::select($query, [
                'min_free_float' => $minFloat,
                'max_free_float' => $maxFloat,
                'max_pe_threshold' => $maxPEThreshold,
            ]);

            // Map results to response format
            $data = collect($results)->map(function ($row) {
                return [
                    'stock_id' => $row->stock_id,
                    'symbol' => $row->symbol,
                    'description' => $row->description,
                    'sector' => [
                        'id' => $row->sector_id,
                        'name' => $row->sector_name,
                    ],
                    'current_price' => (float) $row->latest_price,
                    'ltm_eps' => (float) $row->ttm_eps_value,
                    'pe_ratio' => (float) $row->pe_ratio,
                    'sector_avg_pe' => (float) $row->sector_avg_pe,
                    'pe_vs_sector' => (float) $row->pe_vs_sector,
                    'discount_percent' => (float) $row->discount_pct,
                ];
            });

            // Summary
            $summary = [
                'total_stocks' => $data->count(),
                'avg_discount' => $data->avg('discount_percent') ?: 0,
                'max_discount' => $data->max('discount_percent') ?: 0,
                'min_discount' => $data->min('discount_percent') ?: 0,
            ];

            return $this->successResponse([
                'type' => 'stocks',
                'summary' => $summary,
                'data' => $data,
            ], 'Stock P/E analysis retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to analyze stock P/E data', $e);
        }
    }

    /**
     * Get P/E analysis for sectors
     */
    private function getSectorsPEAnalysis($minFloat, $maxFloat, $shariahOnly): JsonResponse
    {
        // Max P/E threshold
        $maxPEThreshold = 30;

        // Sectors mode shows ALL sectors with their true market statistics
        // No filtering by free float or shariah - we want the complete market picture
        $query = "
        WITH stock_pe_data AS (
            -- Calculate P/E for ALL active stocks to get true sector statistics
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
            ROW_NUMBER() OVER (ORDER BY AVG(spd.pe_ratio) ASC) AS rank,
            sec.id as sector_id,
            sec.name AS sector_name,
            ROUND(AVG(spd.pe_ratio)::numeric, 2) as avg_pe,
            ROUND(MIN(spd.pe_ratio)::numeric, 2) as min_pe,
            ROUND(MAX(spd.pe_ratio)::numeric, 2) as max_pe,
            ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY spd.pe_ratio)::numeric, 2) as median_pe,
            COUNT(*) as total_stocks,
            COUNT(CASE WHEN spd.pe_ratio <= :max_pe_threshold THEN 1 END) as stocks_below_threshold
        FROM stock_pe_data spd
        INNER JOIN sectors sec ON sec.id = spd.sector_id
        WHERE spd.pe_ratio IS NOT NULL
          AND spd.pe_ratio > 0
        GROUP BY spd.sector_id, sec.id, sec.name
        HAVING COUNT(*) >= 3
        ORDER BY rank
        ";

        try {
            $results = DB::select($query, [
                'max_pe_threshold' => $maxPEThreshold,
            ]);

            // Map results to response format
            $data = collect($results)->map(function ($row) {
                return [
                    'sector_id' => $row->sector_id,
                    'sector_name' => $row->sector_name,
                    'total_stocks' => (int) $row->total_stocks,
                    'avg_pe' => (float) $row->avg_pe,
                    'min_pe' => (float) $row->min_pe,
                    'max_pe' => (float) $row->max_pe,
                    'median_pe' => (float) $row->median_pe,
                ];
            });

            // Summary
            $summary = [
                'total_sectors' => $data->count(),
                'avg_sector_pe' => $data->avg('avg_pe') ?: 0,
            ];

            return $this->successResponse([
                'type' => 'sectors',
                'summary' => $summary,
                'data' => $data,
            ], 'Sector P/E analysis retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to analyze sector P/E data', $e);
        }
    }
}
