<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\MansoorSpecialFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LatestResultsController extends Controller
{
    /**
     * Get latest results for all active stocks with financial data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getLatestResults(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sector_id' => 'nullable|uuid',
                'mansoor_special' => 'nullable|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $sectorId = $request->get('sector_id');
            $mansoorSpecial = filter_var($request->get('mansoor_special', false), FILTER_VALIDATE_BOOLEAN);

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                if (!$mansoorService->isAuthorized($request)) {
                    return $mansoorService->getAuthorizationError();
                }
            }

            $results = $this->fetchLatestResults($sectorId, $mansoorSpecial);

            return $this->successResponse([
                'total_results' => count($results),
                'data' => $results,
            ], 'Latest results retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve latest results', $e);
        }
    }

    /**
     * Fetch latest results data from database
     *
     * @param string|null $sectorId
     * @param bool $mansoorSpecial
     * @return array
     */
    private function fetchLatestResults($sectorId, $mansoorSpecial): array
    {
        $sectorCondition = $sectorId ? 'AND s.sector_id = :sector_id' : '';

        $params = [];

        if ($sectorId) {
            $params['sector_id'] = $sectorId;
        }

        // Build mansoor special WHERE clause if needed (applied to final result)
        $mansoorWhereClause = '';
        if ($mansoorSpecial) {
            // Mansoor special: 15-70% free float OR (market cap > 5T AND free float > 70%)
            $mansoorWhereClause = "WHERE (
                (es.free_float::numeric >= (es.total_shares_outstanding::numeric * 0.15)
                 AND es.free_float::numeric <= (es.total_shares_outstanding::numeric * 0.70))
                OR
                ((lp.price::numeric * es.total_shares_outstanding::numeric) > 50000000000
                 AND es.free_float::numeric > (es.total_shares_outstanding::numeric * 0.70))
            )";
        }

        $query = "
        WITH eligible_stocks AS (
            SELECT
                s.id,
                s.symbol,
                s.description,
                s.sector_id,
                sec.name as sector_name,
                s.free_float,
                s.total_shares_outstanding
            FROM stocks s
            LEFT JOIN sectors sec ON s.sector_id = sec.id
            WHERE s.is_active = true
              AND s.market_cap > 0
              $sectorCondition
        ),
        latest_prices AS (
            SELECT
                es.id as stock_id,
                sp.close as price
            FROM eligible_stocks es
            LEFT JOIN LATERAL (
                SELECT close
                FROM stock_prices
                WHERE stock_id = es.id
                ORDER BY date DESC
                LIMIT 1
            ) sp ON true
        ),
        quarterly_results AS (
            SELECT
                es.id,
                fr.data->>'revenue' as revenue,
                fr.data->>'eps' as eps,
                fr.announcement_date,
                fr.period_name,
                fr.created_at,
                ROW_NUMBER() OVER (PARTITION BY es.id ORDER BY fr.created_at DESC) as result_rank
            FROM eligible_stocks es
            LEFT JOIN financial_results fr ON es.id = fr.stock_id
                AND fr.period_type = 'quarterly'
        ),
        latest_result AS (
            SELECT
                id,
                revenue,
                eps,
                announcement_date,
                period_name,
                created_at
            FROM quarterly_results
            WHERE result_rank = 1
        ),
        prior_quarter_result AS (
            SELECT
                id,
                revenue as prior_revenue,
                eps as prior_eps,
                created_at as prior_created_at
            FROM quarterly_results
            WHERE result_rank = 2
        ),
        prior_year_result AS (
            SELECT
                id,
                revenue as prior_year_revenue,
                eps as prior_year_eps,
                created_at as prior_year_created_at
            FROM quarterly_results
            WHERE result_rank = 5
        ),
        ttm_eps_data AS (
            SELECT
                qr.id,
                SUM(CAST(qr.eps AS numeric)) FILTER (WHERE qr.result_rank <= 4) as ttm_eps,
                SUM(CAST(qr.eps AS numeric)) FILTER (WHERE qr.result_rank > 4 AND qr.result_rank <= 8) as prior_ttm_eps
            FROM quarterly_results qr
            GROUP BY qr.id
        ),
        ttm_revenue_data AS (
            SELECT
                qr.id,
                SUM(CAST(qr.revenue AS numeric)) FILTER (WHERE qr.result_rank <= 4) as ttm_revenue,
                SUM(CAST(qr.revenue AS numeric)) FILTER (WHERE qr.result_rank > 4 AND qr.result_rank <= 8) as prior_ttm_revenue
            FROM quarterly_results qr
            GROUP BY qr.id
        )
        SELECT
            es.id as stock_id,
            es.symbol,
            es.description as company_name,
            es.sector_id,
            es.sector_name,
            COALESCE(lr.announcement_date, lr.created_at) as result_date,
            lp.price,
            CAST(lr.revenue AS numeric) as revenue,
            CAST(lr.eps AS numeric) as eps,
            CASE
                WHEN CAST(pqr.prior_eps AS numeric) IS NOT NULL AND CAST(pqr.prior_eps AS numeric) != 0
                    THEN ROUND((((CAST(lr.eps AS numeric) - CAST(pqr.prior_eps AS numeric)) / ABS(CAST(pqr.prior_eps AS numeric))) * 100)::numeric, 2)
                ELSE NULL
            END as qoq_eps_percent,
            CASE
                WHEN CAST(pyr.prior_year_eps AS numeric) IS NOT NULL AND CAST(pyr.prior_year_eps AS numeric) != 0
                    THEN ROUND((((CAST(lr.eps AS numeric) - CAST(pyr.prior_year_eps AS numeric)) / ABS(CAST(pyr.prior_year_eps AS numeric))) * 100)::numeric, 2)
                ELSE NULL
            END as yoy_eps_percent,
            CASE
                WHEN ted.prior_ttm_eps IS NOT NULL AND ted.prior_ttm_eps != 0
                    THEN ROUND(((ted.ttm_eps - ted.prior_ttm_eps) / ABS(ted.prior_ttm_eps) * 100)::numeric, 2)
                ELSE NULL
            END as ttm_over_ttm_eps_percent,
            CASE
                WHEN CAST(pqr.prior_revenue AS numeric) IS NOT NULL AND CAST(pqr.prior_revenue AS numeric) != 0
                    THEN ROUND((((CAST(lr.revenue AS numeric) - CAST(pqr.prior_revenue AS numeric)) / ABS(CAST(pqr.prior_revenue AS numeric))) * 100)::numeric, 2)
                ELSE NULL
            END as qoq_revenue_percent,
            CASE
                WHEN CAST(pyr.prior_year_revenue AS numeric) IS NOT NULL AND CAST(pyr.prior_year_revenue AS numeric) != 0
                    THEN ROUND((((CAST(lr.revenue AS numeric) - CAST(pyr.prior_year_revenue AS numeric)) / ABS(CAST(pyr.prior_year_revenue AS numeric))) * 100)::numeric, 2)
                ELSE NULL
            END as yoy_revenue_percent,
            CASE
                WHEN trd.prior_ttm_revenue IS NOT NULL AND trd.prior_ttm_revenue != 0
                    THEN ROUND(((trd.ttm_revenue - trd.prior_ttm_revenue) / ABS(trd.prior_ttm_revenue) * 100)::numeric, 2)
                ELSE NULL
            END as ttm_over_ttm_revenue_percent
        FROM eligible_stocks es
        LEFT JOIN latest_prices lp ON es.id = lp.stock_id
        LEFT JOIN latest_result lr ON es.id = lr.id
        LEFT JOIN prior_quarter_result pqr ON es.id = pqr.id
        LEFT JOIN prior_year_result pyr ON es.id = pyr.id
        LEFT JOIN ttm_eps_data ted ON es.id = ted.id
        LEFT JOIN ttm_revenue_data trd ON es.id = trd.id
        $mansoorWhereClause
        ORDER BY es.symbol ASC
        ";

        $results = DB::select($query, $params);

        return collect($results)->map(function ($row) {
            return [
                'stock_id' => $row->stock_id,
                'symbol' => $row->symbol,
                'company_name' => $row->company_name,
                'sector_id' => $row->sector_id,
                'sector_name' => $row->sector_name,
                'result_date' => $row->result_date,
                'price' => $row->price ? (float) $row->price : null,
                'revenue' => $row->revenue ? (float) $row->revenue : null,
                'eps' => $row->eps ? (float) $row->eps : null,
                'qoq_eps_percent' => $row->qoq_eps_percent ? (float) $row->qoq_eps_percent : null,
                'yoy_eps_percent' => $row->yoy_eps_percent ? (float) $row->yoy_eps_percent : null,
                'ttm_over_ttm_eps_percent' => $row->ttm_over_ttm_eps_percent ? (float) $row->ttm_over_ttm_eps_percent : null,
                'qoq_revenue_percent' => $row->qoq_revenue_percent ? (float) $row->qoq_revenue_percent : null,
                'yoy_revenue_percent' => $row->yoy_revenue_percent ? (float) $row->yoy_revenue_percent : null,
                'ttm_over_ttm_revenue_percent' => $row->ttm_over_ttm_revenue_percent ? (float) $row->ttm_over_ttm_revenue_percent : null,
            ];
        })->all();
    }
}
