<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EPSRankingController extends Controller
{
    /**
     * Get EPS ranking analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEPSRanking(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'min_float' => 'nullable|numeric|min:0',
                'max_float' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $minFloat = $request->get('min_float', 10);
            $maxFloat = $request->get('max_float', 100);

            // Validate min < max
            if ($minFloat >= $maxFloat) {
                return $this->validationErrorResponse(['min_float' => 'Min float must be less than max float']);
            }

            $query = "
            WITH eligible_stocks AS (
                SELECT
                    s.id,
                    s.symbol,
                    s.description
                FROM stocks s
                WHERE s.is_active = true
                  AND s.market_cap > 0
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
                  AND statement = 'Ratios'
                  AND identifier = 'EPS'
                  AND header IS NOT NULL
                ORDER BY TO_DATE(header, 'Mon YY') DESC
                LIMIT 5
            ),
            eps_data AS (
                SELECT
                    fd.symbol,
                    fd.header,
                    TO_DATE(fd.header, 'Mon YY') as header_date,
                    fd.value::numeric as eps_value,
                    ROW_NUMBER() OVER (PARTITION BY fd.symbol ORDER BY TO_DATE(fd.header, 'Mon YY') DESC) as period_rank
                FROM financial_data fd
                INNER JOIN eligible_stocks es ON fd.symbol = es.symbol
                WHERE fd.type = 'QUARTERLY'
                  AND fd.statement = 'Ratios'
                  AND fd.identifier = 'EPS'
                  AND fd.header IN (SELECT header FROM all_periods)
                  AND fd.value IS NOT NULL
                  AND fd.value ~ '^-?[0-9]+\.?[0-9]*$'
            ),
            latest_eps AS (
                SELECT symbol, header as latest_period, eps_value as latest_eps
                FROM eps_data
                WHERE period_rank = 1
            ),
            sply_eps AS (
                SELECT symbol, header as sply_period, eps_value as sply_eps
                FROM eps_data
                WHERE period_rank = 5
            )
            SELECT
                es.symbol,
                es.description,
                le.latest_period,
                ROUND(le.latest_eps::numeric, 2) as latest_eps,
                se.sply_period,
                ROUND(se.sply_eps::numeric, 2) as sply_eps,
                ROUND((le.latest_eps - se.sply_eps)::numeric, 2) as eps_change,
                CASE
                    WHEN se.sply_eps != 0 THEN
                        ROUND((((le.latest_eps - se.sply_eps) / ABS(se.sply_eps)) * 100)::numeric, 2)
                    ELSE NULL
                END as eps_change_percent
            FROM eligible_stocks es
            INNER JOIN latest_eps le ON es.symbol = le.symbol
            INNER JOIN sply_eps se ON es.symbol = se.symbol
            WHERE le.latest_eps IS NOT NULL
              AND se.sply_eps IS NOT NULL
            ORDER BY eps_change_percent DESC NULLS LAST
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
                        'latest_eps' => $row->latest_eps ? (float) $row->latest_eps : null,
                        'sply_eps' => $row->sply_eps ? (float) $row->sply_eps : null,
                        'eps_change' => $row->eps_change ? (float) $row->eps_change : null,
                        'eps_change_percent' => $row->eps_change_percent ? (float) $row->eps_change_percent : null,
                    ];
                });

                $summary = [
                    'total_stocks' => $data->count(),
                    'avg_change_percent' => $data->avg('eps_change_percent') ?: 0,
                    'max_change_percent' => $data->max('eps_change_percent') ?: 0,
                    'min_change_percent' => $data->min('eps_change_percent') ?: 0,
                ];

                return $this->successResponse([
                    'summary' => $summary,
                    'data' => $data,
                ], 'EPS ranking data retrieved successfully');

            } catch (\Exception $e) {
                return $this->serverErrorResponse('Failed to retrieve EPS ranking data', $e);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving EPS ranking', $e);
        }
    }
}
