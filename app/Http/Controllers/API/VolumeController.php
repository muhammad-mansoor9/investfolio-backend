<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VolumeController extends Controller
{
    /**
     * Get volume analysis data based on the Python volume analyzer script
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'analysis_date' => 'sometimes|date',
                'min_free_float_pct' => 'sometimes|numeric|min:0|max:100',
                'max_free_float_pct' => 'sometimes|numeric|min:0|max:100',
                'volume_threshold' => 'sometimes|numeric|min:1',
                'min_trading_days' => 'sometimes|integer|min:5|max:30',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            // Get parameters with defaults
            $analysisDate = $request->get('analysis_date') ?: DB::selectOne("SELECT MAX(date) as latest_date FROM stock_prices")->latest_date;
            $minFreeFloat = $request->get('min_free_float_pct', 15.0);
            $maxFreeFloat = $request->get('max_free_float_pct', 30.0);
            $volumeThreshold = $request->get('volume_threshold', 2.0);
            $minTradingDays = $request->get('min_trading_days', 15);

            // Main volume analysis query based on the Python script
            $volumeAnalysis = DB::select("
                WITH latest_trading_date AS (
                    SELECT ? as latest_date
                ),
                trading_dates_sequence AS (
                    SELECT DISTINCT date
                    FROM stock_prices
                    WHERE date <= ?
                    ORDER BY date DESC
                    LIMIT 20
                ),
                eligible_stocks AS (
                    SELECT
                        s.id,
                        s.symbol,
                        s.sector_id,
                        ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_pct
                    FROM stocks s
                    INNER JOIN stock_prices sp_latest ON s.id = sp_latest.stock_id AND sp_latest.date = ?
                    WHERE s.is_active = true
                      AND s.market_cap > 0
                      AND s.total_shares_outstanding > 0
                      AND s.free_float > 0
                      AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100) BETWEEN ? AND ?
                ),
                stock_price_data AS (
                    SELECT
                        sp.stock_id,
                        sp.date,
                        sp.volume,
                        sp.close,
                        ROW_NUMBER() OVER (PARTITION BY sp.stock_id ORDER BY sp.date DESC) as trading_day_rank
                    FROM stock_prices sp
                    INNER JOIN trading_dates_sequence tds ON sp.date = tds.date
                    INNER JOIN eligible_stocks es ON sp.stock_id = es.id
                ),
                stock_daily_volumes AS (
                    SELECT
                        stock_id,
                        MAX(CASE WHEN trading_day_rank = 1 THEN volume END) AS today_volume,
                        MAX(CASE WHEN trading_day_rank = 1 THEN close END) AS today_close,
                        MAX(CASE WHEN trading_day_rank = 2 THEN volume END) AS yesterday_volume,
                        MAX(CASE WHEN trading_day_rank = 2 THEN close END) AS yesterday_close,
                        MAX(CASE WHEN trading_day_rank = 3 THEN volume END) AS two_days_ago_volume,
                        AVG(volume) AS avg_20d_volume,
                        AVG(CASE WHEN trading_day_rank BETWEEN 1 AND 3 THEN volume END) AS avg_3d_volume
                    FROM stock_price_data
                    WHERE trading_day_rank <= 20
                    GROUP BY stock_id
                    HAVING MAX(CASE WHEN trading_day_rank = 1 THEN volume END) IS NOT NULL
                       AND MAX(CASE WHEN trading_day_rank = 2 THEN volume END) IS NOT NULL
                       AND MAX(CASE WHEN trading_day_rank = 3 THEN volume END) IS NOT NULL
                       AND AVG(volume) > 0
                       AND COUNT(*) >= ?
                )
                SELECT
                    ROW_NUMBER() OVER (
                        ORDER BY
                            sdv.today_volume DESC,
                            sdv.yesterday_volume DESC,
                            sdv.two_days_ago_volume DESC
                    ) AS rank,
                    s.symbol,
                    s.description as company_name,
                    sec.name as sector_name,
                    CONCAT(s.symbol, ' (', sec.name, ')') AS stock_sector,
                    sdv.today_volume,
                    sdv.yesterday_volume,
                    sdv.two_days_ago_volume,
                    ROUND((sdv.today_volume / NULLIF(sdv.avg_20d_volume, 0))::numeric, 2) AS today_vs_20d,
                    ROUND((sdv.avg_3d_volume / NULLIF(sdv.avg_20d_volume, 0))::numeric, 2) AS avg3d_vs_20d,
                    ROUND((((sdv.today_close - sdv.yesterday_close) / NULLIF(sdv.yesterday_close, 0)) * 100)::numeric, 2) AS price_change_pct,
                    es.free_float_pct,
                    ROUND(sdv.avg_20d_volume::numeric, 0) as avg_20d_volume,
                    ROUND(sdv.avg_3d_volume::numeric, 0) as avg_3d_volume
                FROM stock_daily_volumes sdv
                INNER JOIN eligible_stocks es ON es.id = sdv.stock_id
                INNER JOIN stocks s ON s.id = sdv.stock_id
                INNER JOIN sectors sec ON sec.id = s.sector_id
                WHERE (sdv.today_volume / NULLIF(sdv.avg_20d_volume, 0)) >= ?
                ORDER BY rank
            ", [
                $analysisDate, $analysisDate, $analysisDate,
                $minFreeFloat, $maxFreeFloat, $minTradingDays, $volumeThreshold
            ]);

            return $this->successResponse([
                'analysis_date' => $analysisDate,
                'filters' => [
                    'min_free_float_pct' => $minFreeFloat,
                    'max_free_float_pct' => $maxFreeFloat,
                    'volume_threshold' => $volumeThreshold,
                    'min_trading_days' => $minTradingDays,
                ],
                'summary' => [
                    'total_stocks_analyzed' => count($volumeAnalysis),
                    'stocks_meeting_criteria' => count($volumeAnalysis)
                ],
                'volume_analysis' => $volumeAnalysis
            ], 'Volume analysis completed successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred during volume analysis', $e);
        }
    }

    /**
     * Get sector volume summary based on the Python volume analyzer script
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSectorVolumeSummary(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'analysis_date' => 'sometimes|date',
                'min_free_float_pct' => 'sometimes|numeric|min:0|max:100',
                'max_free_float_pct' => 'sometimes|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            // Get parameters with defaults
            $analysisDate = $request->get('analysis_date') ?: DB::selectOne("SELECT MAX(date) as latest_date FROM stock_prices")->latest_date;
            $minFreeFloat = $request->get('min_free_float_pct', 15.0);
            $maxFreeFloat = $request->get('max_free_float_pct', 30.0);

            // Sector volume summary query based on the Python script
            $sectorSummary = DB::select("
                WITH latest_trading_date AS (
                    SELECT ? as latest_date
                ),
                trading_dates_sequence AS (
                    SELECT DISTINCT date
                    FROM stock_prices
                    WHERE date <= ?
                    ORDER BY date DESC
                    LIMIT 25
                ),
                eligible_stocks AS (
                    SELECT
                        s.id,
                        s.sector_id,
                        ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_pct
                    FROM stocks s
                    INNER JOIN stock_prices sp_latest ON s.id = sp_latest.stock_id AND sp_latest.date = ?
                    WHERE s.is_active = true
                      AND s.market_cap > 0
                      AND s.total_shares_outstanding > 0
                      AND s.free_float > 0
                      AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100) BETWEEN ? AND ?
                ),
                stock_timeseries AS (
                    SELECT
                        sp.stock_id,
                        sp.date,
                        sp.volume,
                        sp.close,
                        es.sector_id,
                        LAG(sp.volume) OVER (PARTITION BY sp.stock_id ORDER BY sp.date) AS yesterday_volume,
                        LAG(sp.close) OVER (PARTITION BY sp.stock_id ORDER BY sp.date) AS yesterday_close,
                        AVG(sp.volume) OVER (
                            PARTITION BY sp.stock_id
                            ORDER BY sp.date
                            ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
                        ) AS avg_3d_volume,
                        AVG(sp.volume) OVER (
                            PARTITION BY sp.stock_id
                            ORDER BY sp.date
                            ROWS BETWEEN 19 PRECEDING AND CURRENT ROW
                        ) AS avg_20d_volume
                    FROM stock_prices sp
                    INNER JOIN trading_dates_sequence tds ON sp.date = tds.date
                    INNER JOIN eligible_stocks es ON sp.stock_id = es.id
                ),
                today_data AS (
                    SELECT
                        sts.*,
                        ROW_NUMBER() OVER (PARTITION BY sts.stock_id ORDER BY sts.date DESC) AS rn
                    FROM stock_timeseries sts
                    WHERE sts.date = ?
                )
                SELECT
                    sec.name AS sector,
                    SUM(td.volume) AS today_volume,
                    ROUND(AVG(td.avg_3d_volume)::numeric, 0)::bigint AS avg_3d_volume,
                    ROUND(AVG(td.avg_20d_volume)::numeric, 0)::bigint AS avg_20d_volume,
                    ROUND((SUM(td.volume) / NULLIF(SUM(td.yesterday_volume), 0))::numeric, 2) AS vs_yesterday,
                    ROUND((AVG(td.avg_3d_volume) / NULLIF(AVG(td.avg_20d_volume), 0))::numeric, 2) AS avg3d_vs_20d,
                    ROUND(AVG(((td.close - td.yesterday_close) / NULLIF(td.yesterday_close, 0)) * 100)::numeric, 2) AS avg_change_pct,
                    COUNT(*) as stocks_in_sector
                FROM today_data td
                INNER JOIN sectors sec ON sec.id = td.sector_id
                WHERE td.rn = 1
                GROUP BY sec.id, sec.name
                ORDER BY vs_yesterday DESC
            ", [
                $analysisDate, $analysisDate, $analysisDate,
                $minFreeFloat, $maxFreeFloat, $analysisDate
            ]);

            return $this->successResponse([
                'analysis_date' => $analysisDate,
                'filters' => [
                    'min_free_float_pct' => $minFreeFloat,
                    'max_free_float_pct' => $maxFreeFloat,
                ],
                'summary' => [
                    'total_sectors_analyzed' => count($sectorSummary),
                    'total_stocks_in_analysis' => collect($sectorSummary)->sum('stocks_in_sector')
                ],
                'sector_summary' => $sectorSummary
            ], 'Sector volume summary retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector volume summary', $e);
        }
    }

    /**
     * Get combined volume report (stocks + sectors)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVolumeReport(Request $request): JsonResponse
    {
        try {
            // Get both volume analysis and sector summary
            $volumeResponse = $this->getVolumeAnalysis($request);
            $sectorResponse = $this->getSectorVolumeSummary($request);

            $volumeData = json_decode($volumeResponse->getContent(), true);
            $sectorData = json_decode($sectorResponse->getContent(), true);

            if (!$volumeData['success'] || !$sectorData['success']) {
                return $this->serverErrorResponse('Failed to generate complete volume report');
            }

            return $this->successResponse([
                'analysis_date' => $volumeData['data']['analysis_date'],
                'filters' => $volumeData['data']['filters'],
                'summary' => [
                    'stocks_meeting_criteria' => $volumeData['data']['summary']['stocks_meeting_criteria'],
                    'sectors_analyzed' => $sectorData['data']['summary']['total_sectors_analyzed'],
                    'total_stocks_in_sectors' => $sectorData['data']['summary']['total_stocks_in_analysis']
                ],
                'volume_analysis' => $volumeData['data']['volume_analysis'],
                'sector_summary' => $sectorData['data']['sector_summary']
            ], 'Complete volume report generated successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while generating volume report', $e);
        }
    }
}
