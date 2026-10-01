<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\InvestfolioLiquidShariahFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecentEarningsController extends Controller
{
    /**
     * Quarterly Revenue / Diluted EPS growth (QoQ, YoY, TTM-over-TTM) per active stock, from financial_data.
     */
    public function getRecentEarnings(Request $request): JsonResponse
    {
        try {
            $investfolioLiquidShariah = $request->boolean('investfolio_liquid_shariah', false);

            // Comparison quarters are matched by date offset from each stock's latest period rather than by
            // row position, because some stocks have missing quarters and the Nth-previous row would then
            // compare against the wrong period. A TTM window is only valid when all 4 of its quarters exist.
            $query = "
            WITH quarterly AS (
                SELECT
                    fd.symbol,
                    TO_DATE(fd.header, 'Mon \"''\"YY') AS period,
                    MAX(CASE WHEN fd.identifier = 'Total Revenues' THEN fd.value::numeric END) AS revenue,
                    MAX(CASE WHEN fd.identifier = 'EPS Diluted' THEN fd.value::numeric END) AS eps
                FROM financial_data fd
                WHERE fd.type = 'QUARTERLY'
                  AND fd.statement = 'Income Statement'
                  AND fd.identifier IN ('Total Revenues', 'EPS Diluted')
                  AND fd.value ~ '^-?[0-9]+(\\.[0-9]+)?$'
                GROUP BY fd.symbol, fd.header
            ),
            with_latest AS (
                SELECT q.*, MAX(q.period) OVER (PARTITION BY q.symbol) AS latest_period
                FROM quarterly q
            ),
            last_eight_quarters AS (
                SELECT
                    symbol,
                    latest_period,
                    MAX(CASE WHEN period = latest_period THEN revenue END) AS rev_q0,
                    MAX(CASE WHEN period = latest_period THEN eps END) AS eps_q0,
                    MAX(CASE WHEN period = latest_period - INTERVAL '3 months' THEN revenue END) AS rev_prev_q,
                    MAX(CASE WHEN period = latest_period - INTERVAL '3 months' THEN eps END) AS eps_prev_q,
                    MAX(CASE WHEN period = latest_period - INTERVAL '12 months' THEN revenue END) AS rev_sply,
                    MAX(CASE WHEN period = latest_period - INTERVAL '12 months' THEN eps END) AS eps_sply,
                    CASE WHEN COUNT(revenue) FILTER (WHERE period > latest_period - INTERVAL '12 months') = 4
                         THEN SUM(revenue) FILTER (WHERE period > latest_period - INTERVAL '12 months') END AS rev_ttm,
                    CASE WHEN COUNT(eps) FILTER (WHERE period > latest_period - INTERVAL '12 months') = 4
                         THEN SUM(eps) FILTER (WHERE period > latest_period - INTERVAL '12 months') END AS eps_ttm,
                    CASE WHEN COUNT(revenue) FILTER (WHERE period <= latest_period - INTERVAL '12 months') = 4
                         THEN SUM(revenue) FILTER (WHERE period <= latest_period - INTERVAL '12 months') END AS rev_ttm_prev,
                    CASE WHEN COUNT(eps) FILTER (WHERE period <= latest_period - INTERVAL '12 months') = 4
                         THEN SUM(eps) FILTER (WHERE period <= latest_period - INTERVAL '12 months') END AS eps_ttm_prev
                FROM with_latest
                WHERE period > latest_period - INTERVAL '24 months'
                GROUP BY symbol, latest_period
            )
            SELECT
                s.id AS stock_id,
                s.symbol,
                s.description,
                s.sector_id,
                sec.name AS sector_name,
                s.is_shariah,
                s.free_float,
                s.total_shares_outstanding,
                s.market_cap,
                lp.close AS current_price,
                q.latest_period,
                ROUND((q.rev_q0 - q.rev_prev_q) / NULLIF(ABS(q.rev_prev_q), 0) * 100, 2) AS revenue_qoq,
                ROUND((q.eps_q0 - q.eps_prev_q) / NULLIF(ABS(q.eps_prev_q), 0) * 100, 2) AS eps_qoq,
                ROUND((q.rev_q0 - q.rev_sply) / NULLIF(ABS(q.rev_sply), 0) * 100, 2) AS revenue_yoy,
                ROUND((q.eps_q0 - q.eps_sply) / NULLIF(ABS(q.eps_sply), 0) * 100, 2) AS eps_yoy,
                ROUND((q.rev_ttm - q.rev_ttm_prev) / NULLIF(ABS(q.rev_ttm_prev), 0) * 100, 2) AS revenue_ttm,
                ROUND((q.eps_ttm - q.eps_ttm_prev) / NULLIF(ABS(q.eps_ttm_prev), 0) * 100, 2) AS eps_ttm
            FROM last_eight_quarters q
            INNER JOIN stocks s ON s.symbol = q.symbol AND s.is_active = true
            LEFT JOIN sectors sec ON sec.id = s.sector_id
            LEFT JOIN LATERAL (
                SELECT sp.close
                FROM stock_prices sp
                WHERE sp.stock_id = s.id
                ORDER BY sp.date DESC
                LIMIT 1
            ) lp ON true
            ORDER BY q.latest_period DESC, s.symbol ASC
            ";

            $results = DB::select($query);

            $filterService = $investfolioLiquidShariah ? new InvestfolioLiquidShariahFilterService() : null;
            $toFloat = fn ($value) => $value === null ? null : (float) $value;

            $data = collect($results)
                ->filter(fn ($row) => !$filterService || $filterService->passesFilter($row))
                ->map(fn ($row) => [
                    'stock_id' => $row->stock_id,
                    'symbol' => $row->symbol,
                    'description' => $row->description,
                    'sector_id' => $row->sector_id,
                    'sector_name' => $row->sector_name,
                    'is_shariah' => (bool) $row->is_shariah,
                    'current_price' => $toFloat($row->current_price),
                    'latest_period' => $row->latest_period,
                    'revenue_qoq' => $toFloat($row->revenue_qoq),
                    'eps_qoq' => $toFloat($row->eps_qoq),
                    'revenue_yoy' => $toFloat($row->revenue_yoy),
                    'eps_yoy' => $toFloat($row->eps_yoy),
                    'revenue_ttm' => $toFloat($row->revenue_ttm),
                    'eps_ttm' => $toFloat($row->eps_ttm),
                ])
                ->values();

            return $this->successResponse([
                'total_stocks' => $data->count(),
                'data' => $data,
            ], 'Recent earnings retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving recent earnings', $e);
        }
    }
}
