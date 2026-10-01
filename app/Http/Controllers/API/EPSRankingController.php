<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\InvestfolioLiquidShariahFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EPSRankingController extends Controller
{
    /**
     * Get EPS ranking analysis from financial_results
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEPSRanking(Request $request): JsonResponse
    {
        try {
            $investfolioLiquidShariah = $request->boolean('investfolio_liquid_shariah', false);

            $query = "
            WITH latest_four AS (
                SELECT
                    fr.stock_id,
                    fr.announcement_date,
                    fr.eps,
                    ROW_NUMBER() OVER (PARTITION BY fr.stock_id ORDER BY fr.announcement_date DESC) as rn
                FROM financial_results fr
                INNER JOIN stocks s ON fr.stock_id = s.id
                WHERE s.is_active = true
                  AND fr.eps IS NOT NULL
            ),
            aggregated AS (
                SELECT
                    stock_id,
                    MAX(CASE WHEN rn = 1 THEN announcement_date END) as latest_result_date,
                    MAX(CASE WHEN rn = 1 THEN eps END) as current_eps,
                    SUM(CASE WHEN rn BETWEEN 1 AND 4 THEN eps ELSE 0 END) as ttm_eps,
                    MAX(CASE WHEN rn = 2 THEN eps END) as prev_eps,
                    MAX(CASE WHEN rn = 5 THEN eps END) as yoy_eps,
                    SUM(CASE WHEN rn BETWEEN 5 AND 8 THEN eps ELSE 0 END) as ttm_prev_eps
                FROM latest_four
                WHERE rn <= 8
                GROUP BY stock_id
            )
            SELECT
                s.id as stock_id,
                s.symbol,
                s.description,
                a.latest_result_date,
                ROUND(a.current_eps::numeric, 4) as current_eps,
                ROUND(a.ttm_eps::numeric, 4) as ttm_eps,
                CASE
                    WHEN a.prev_eps IS NOT NULL AND a.prev_eps > 0 THEN
                        ROUND(((a.current_eps - a.prev_eps) / a.prev_eps * 100)::numeric, 2)
                    ELSE NULL
                END as change_qoq_percent,
                CASE
                    WHEN a.yoy_eps IS NOT NULL AND a.yoy_eps > 0 THEN
                        ROUND(((a.current_eps - a.yoy_eps) / a.yoy_eps * 100)::numeric, 2)
                    ELSE NULL
                END as change_yoy_percent,
                CASE
                    WHEN a.ttm_prev_eps IS NOT NULL AND a.ttm_prev_eps > 0 THEN
                        ROUND(((a.ttm_eps - a.ttm_prev_eps) / a.ttm_prev_eps * 100)::numeric, 2)
                    ELSE NULL
                END as change_ttm_over_ttm_percent,
                s.is_shariah
            FROM aggregated a
            INNER JOIN stocks s ON a.stock_id = s.id
            ORDER BY s.symbol ASC
            ";

            $results = DB::select($query);

            $filterService = $investfolioLiquidShariah ? new InvestfolioLiquidShariahFilterService() : null;

            $data = collect($results)
                ->filter(function ($row) use ($investfolioLiquidShariah, $filterService) {
                    if ($investfolioLiquidShariah && !$filterService->passesFilter($row)) {
                        return false;
                    }
                    return true;
                })
                ->map(function ($row) {
                    return [
                        'stock_id' => $row->stock_id,
                        'symbol' => $row->symbol,
                        'description' => $row->description,
                        'latest_result_date' => $row->latest_result_date,
                        'current_eps' => $row->current_eps ? (float) $row->current_eps : null,
                        'ttm_eps' => $row->ttm_eps ? (float) $row->ttm_eps : null,
                        'change_qoq_percent' => $row->change_qoq_percent ? (float) $row->change_qoq_percent : null,
                        'change_yoy_percent' => $row->change_yoy_percent ? (float) $row->change_yoy_percent : null,
                        'change_ttm_over_ttm_percent' => $row->change_ttm_over_ttm_percent ? (float) $row->change_ttm_over_ttm_percent : null,
                        'is_shariah' => (bool) $row->is_shariah,
                    ];
                });

            $summary = [
                'total_stocks' => $data->count(),
                'avg_qoq_change' => $data->avg('change_qoq_percent') ?: 0,
                'avg_yoy_change' => $data->avg('change_yoy_percent') ?: 0,
                'avg_ttm_change' => $data->avg('change_ttm_over_ttm_percent') ?: 0,
            ];

            return $this->successResponse([
                'summary' => $summary,
                'data' => $data,
            ], 'EPS ranking data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving EPS ranking', $e);
        }
    }
}
