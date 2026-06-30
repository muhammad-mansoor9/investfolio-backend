<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RevenueRankingController extends Controller
{
    /**
     * Get Revenue ranking analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRevenueRanking(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'min_float' => 'nullable|numeric|min:0',
                'max_float' => 'nullable|numeric|min:0',
                'shariah_only' => 'nullable|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $minFloat = $request->get('min_float', 10);
            $maxFloat = $request->get('max_float', 100);
            $shariahOnly = filter_var($request->get('shariah_only', false), FILTER_VALIDATE_BOOLEAN);

            // Validate min < max
            if ($minFloat >= $maxFloat) {
                return $this->validationErrorResponse(['min_float' => 'Min float must be less than max float']);
            }

            $shariahCondition = $shariahOnly ? 'AND s.is_shariah = true' : '';

            $query = "
            WITH eligible_stocks AS (
                SELECT
                    s.id,
                    s.symbol,
                    s.description
                FROM stocks s
                WHERE s.is_active = true
                  AND s.market_cap > 0
                  $shariahCondition
                  AND s.total_shares_outstanding > 0
                  AND s.free_float > 0
                  AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100)
                      BETWEEN :min_free_float AND :max_free_float
            ),
            all_periods AS (
                SELECT DISTINCT
                    header,
                    TO_DATE(header, 'Mon YY') as header_date
                FROM financial_data
                WHERE type = 'QUARTERLY'
                  AND statement = 'Income Statement'
                  AND identifier = 'Total Revenues'
                  AND header IS NOT NULL
                ORDER BY TO_DATE(header, 'Mon YY') DESC
                LIMIT 5
            ),
            revenue_data AS (
                SELECT
                    fd.symbol,
                    fd.header,
                    TO_DATE(fd.header, 'Mon YY') as header_date,
                    fd.value::numeric as revenue_value,
                    ROW_NUMBER() OVER (PARTITION BY fd.symbol ORDER BY TO_DATE(fd.header, 'Mon YY') DESC) as period_rank
                FROM financial_data fd
                INNER JOIN eligible_stocks es ON fd.symbol = es.symbol
                WHERE fd.type = 'QUARTERLY'
                  AND fd.statement = 'Income Statement'
                  AND fd.identifier = 'Total Revenues'
                  AND fd.header IN (SELECT header FROM all_periods)
                  AND fd.value IS NOT NULL
                  AND fd.value ~ '^-?[0-9]+\.?[0-9]*$'
            ),
            latest_revenue AS (
                SELECT symbol, header as latest_period, revenue_value as latest_revenue
                FROM revenue_data
                WHERE period_rank = 1
            ),
            sply_revenue AS (
                SELECT symbol, header as sply_period, revenue_value as sply_revenue
                FROM revenue_data
                WHERE period_rank = 5
            )
            SELECT
                es.symbol,
                es.description,
                lr.latest_period,
                ROUND(lr.latest_revenue::numeric, 2) as latest_revenue,
                sr.sply_period,
                ROUND(sr.sply_revenue::numeric, 2) as sply_revenue,
                ROUND((lr.latest_revenue - sr.sply_revenue)::numeric, 2) as revenue_change,
                CASE
                    WHEN sr.sply_revenue != 0 THEN
                        ROUND((((lr.latest_revenue - sr.sply_revenue) / ABS(sr.sply_revenue)) * 100)::numeric, 2)
                    ELSE NULL
                END as revenue_change_percent
            FROM eligible_stocks es
            INNER JOIN latest_revenue lr ON es.symbol = lr.symbol
            INNER JOIN sply_revenue sr ON es.symbol = sr.symbol
            WHERE lr.latest_revenue IS NOT NULL
              AND sr.sply_revenue IS NOT NULL
            ORDER BY revenue_change_percent DESC NULLS LAST
            ";

            try {
                $results = DB::select($query, [
                    'min_free_float' => $minFloat,
                    'max_free_float' => $maxFloat,
                ]);

                $data = collect($results)->map(function ($row) {
                    return [
                        'symbol' => $row->symbol,
                        'description' => $row->description,
                        'latest_period' => $row->latest_period,
                        'latest_revenue' => $row->latest_revenue ? (float) $row->latest_revenue : null,
                        'sply_revenue' => $row->sply_revenue ? (float) $row->sply_revenue : null,
                        'revenue_change' => $row->revenue_change ? (float) $row->revenue_change : null,
                        'revenue_change_percent' => $row->revenue_change_percent ? (float) $row->revenue_change_percent : null,
                    ];
                });

                $summary = [
                    'total_stocks' => $data->count(),
                    'avg_change_percent' => $data->avg('revenue_change_percent') ?: 0,
                    'max_change_percent' => $data->max('revenue_change_percent') ?: 0,
                    'min_change_percent' => $data->min('revenue_change_percent') ?: 0,
                ];

                return $this->successResponse([
                    'summary' => $summary,
                    'data' => $data,
                ], 'Revenue ranking data retrieved successfully');

            } catch (\Exception $e) {
                return $this->serverErrorResponse('Failed to retrieve Revenue ranking data', $e);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving Revenue ranking', $e);
        }
    }
}
