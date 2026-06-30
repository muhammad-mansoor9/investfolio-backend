<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class InsiderTradingController extends Controller
{
    /**
     * Get insider trading data for a specific period.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInsiderTradingData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'min_free_float_pct' => 'nullable|numeric|min:0|max:100',
                'max_free_float_pct' => 'nullable|numeric|min:0|max:100',
                'min_change_threshold' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $minFreeFloatPct = $request->input('min_free_float_pct', 15.0);
            $maxFreeFloatPct = $request->input('max_free_float_pct', 60.0);
            $minChangeThreshold = $request->input('min_change_threshold', 10.0);
            $shariahOnly = true; // Always filter for Shariah stocks

            // Count excluded stock splits
            $splitsQuery = DB::table('stock_share_changes_audit as sca')
                ->join('stocks as s', 'sca.stock_id', '=', 's.id')
                ->whereBetween('sca.change_date', [$startDate, $endDate])
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0)
                ->whereRaw('((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100) BETWEEN ? AND ?',
                    [$minFreeFloatPct, $maxFreeFloatPct])
                ->where('sca.change_type', 'stock_split');

            if ($shariahOnly) {
                $splitsQuery->where('s.is_shariah', true);
            }

            $splitsExcluded = $splitsQuery->count();

            // Main query for period changes
            $shariahFilter = $shariahOnly ? "AND s.is_shariah = true" : "";

            $changes = DB::select("
                WITH period_changes AS (
                    SELECT
                        sca.symbol,
                        sca.stock_id,
                        s.description as company_name,
                        sec.name as sector,
                        s.total_shares_outstanding,
                        s.free_float,
                        ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS current_free_float_pct,

                        MIN(sca.change_date) as first_change_date,
                        MAX(sca.change_date) as last_change_date,

                        MIN(CASE WHEN sca.change_type = 'free_float' THEN sca.old_value END) as period_start_free_float,
                        MAX(CASE WHEN sca.change_type = 'free_float' THEN sca.new_value END) as period_end_free_float,

                        MIN(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN sca.old_value END) as period_start_total_shares,
                        MAX(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN sca.new_value END) as period_end_total_shares,

                        COUNT(CASE WHEN sca.change_type = 'free_float' THEN 1 END) as ff_changes_count,
                        COUNT(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN 1 END) as shares_changes_count

                    FROM stock_share_changes_audit sca
                    INNER JOIN stocks s ON sca.stock_id = s.id
                    INNER JOIN sectors sec ON s.sector_id = sec.id
                    WHERE sca.change_date BETWEEN ? AND ?
                      AND s.is_active = true
                      AND s.market_cap > 0
                      {$shariahFilter}
                      AND s.total_shares_outstanding > 0
                      AND s.free_float > 0
                      AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100) BETWEEN ? AND ?
                      AND sca.change_type != 'stock_split'
                      AND sca.change_type IN ('free_float', 'total_shares_outstanding')
                    GROUP BY sca.symbol, sca.stock_id, s.description, sec.name, s.total_shares_outstanding, s.free_float
                ),
                calculated_changes AS (
                    SELECT
                        pc.*,
                        CASE
                            WHEN pc.period_start_free_float > 0 AND pc.period_end_free_float IS NOT NULL THEN
                                ROUND(((pc.period_end_free_float - pc.period_start_free_float) / pc.period_start_free_float::numeric * 100), 2)
                            ELSE NULL
                        END as cumulative_ff_change_pct,

                        CASE
                            WHEN pc.period_start_total_shares > 0 AND pc.period_end_total_shares IS NOT NULL THEN
                                ROUND(((pc.period_end_total_shares - pc.period_start_total_shares) / pc.period_start_total_shares::numeric * 100), 2)
                            ELSE NULL
                        END as cumulative_shares_change_pct
                    FROM period_changes pc
                )
                SELECT *
                FROM calculated_changes cc
                WHERE
                    (ABS(COALESCE(cc.cumulative_ff_change_pct, 0)) >= ?)
                    OR
                    (ABS(COALESCE(cc.cumulative_shares_change_pct, 0)) >= ?)
                ORDER BY
                    GREATEST(
                        ABS(COALESCE(cc.cumulative_ff_change_pct, 0)),
                        ABS(COALESCE(cc.cumulative_shares_change_pct, 0))
                    ) DESC
            ", [
                $startDate,
                $endDate,
                $minFreeFloatPct,
                $maxFreeFloatPct,
                $minChangeThreshold,
                $minChangeThreshold
            ]);

            // Categorize the changes
            $insiderBuying = [];
            $insiderSelling = [];
            $shareBuybacks = [];
            $shareIssuances = [];
            $shareIssuancesFlagged = [];

            foreach ($changes as $change) {
                $changeData = [
                    'symbol' => $change->symbol,
                    'stock_id' => $change->stock_id,
                    'company_name' => $change->company_name,
                    'sector' => $change->sector,
                    'first_change_date' => $change->first_change_date,
                    'last_change_date' => $change->last_change_date,
                    'period_start_free_float' => (int)$change->period_start_free_float,
                    'period_end_free_float' => (int)$change->period_end_free_float,
                    'period_start_total_shares' => (int)$change->period_start_total_shares,
                    'period_end_total_shares' => (int)$change->period_end_total_shares,
                    'ff_changes_count' => $change->ff_changes_count,
                    'shares_changes_count' => $change->shares_changes_count,
                    'current_free_float_pct' => (float)$change->current_free_float_pct,
                    'cumulative_ff_change_pct' => $change->cumulative_ff_change_pct ? (float)$change->cumulative_ff_change_pct : null,
                    'cumulative_shares_change_pct' => $change->cumulative_shares_change_pct ? (float)$change->cumulative_shares_change_pct : null,
                ];

                // Categorize based on free float changes
                if ($changeData['cumulative_ff_change_pct'] !== null) {
                    if ($changeData['cumulative_ff_change_pct'] <= -$minChangeThreshold) {
                        $insiderBuying[] = $changeData;
                    } elseif ($changeData['cumulative_ff_change_pct'] >= $minChangeThreshold) {
                        $insiderSelling[] = $changeData;
                    }
                }

                // Categorize based on total shares changes
                if ($changeData['cumulative_shares_change_pct'] !== null) {
                    if ($changeData['cumulative_shares_change_pct'] <= -$minChangeThreshold) {
                        $shareBuybacks[] = $changeData;
                    } elseif ($changeData['cumulative_shares_change_pct'] >= $minChangeThreshold) {
                        // Get historical analysis for share issuances
                        $historicalAnalysis = $this->analyzeHistoricalSharePatterns(
                            $change->stock_id,
                            $change->total_shares_outstanding
                        );

                        $changeData = array_merge($changeData, $historicalAnalysis);

                        // Separate flagged chronic issuers
                        if ($historicalAnalysis['recommendation'] === 'bearish') {
                            $shareIssuancesFlagged[] = $changeData;
                        } else {
                            $shareIssuances[] = $changeData;
                        }
                    }
                }
            }

            // Calculate summary
            $bullishScore = count($insiderBuying) + (count($shareBuybacks) * 2);
            $bearishScore = count($insiderSelling) + (count($shareIssuances) * 1.5) + (count($shareIssuancesFlagged) * 3);

            if ($bullishScore > $bearishScore && $bullishScore > 0) {
                $periodSignal = 'bullish';
                $signalStrength = 'BULLISH';
            } elseif ($bearishScore > $bullishScore && $bearishScore > 0) {
                $periodSignal = 'bearish';
                $signalStrength = 'BEARISH';
            } else {
                $periodSignal = 'neutral';
                $signalStrength = 'NEUTRAL';
            }

            $summary = [
                'total_changes' => count($changes),
                'buying_events' => count($insiderBuying),
                'selling_events' => count($insiderSelling),
                'buyback_events' => count($shareBuybacks),
                'issuance_events' => count($shareIssuances),
                'flagged_events' => count($shareIssuancesFlagged),
                'splits_excluded' => $splitsExcluded,
                'period_signal' => $periodSignal,
                'signal_strength' => $signalStrength,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Insider trading data retrieved successfully',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'summary' => $summary,
                    'insider_buying' => $insiderBuying,
                    'insider_selling' => $insiderSelling,
                    'share_buybacks' => $shareBuybacks,
                    'share_issuances' => $shareIssuances,
                    'share_issuances_flagged' => $shareIssuancesFlagged,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve insider trading data',
                'errors' => ['error' => $e->getMessage()],
                'data' => null
            ], 500);
        }
    }

    /**
     * Analyze historical share issuance patterns.
     *
     * @param string $stockId
     * @param int $currentShares
     * @return array
     */
    private function analyzeHistoricalSharePatterns(string $stockId, int $currentShares): array
    {
        try {
            $threeYearsAgo = Carbon::now()->subYears(3)->toDateString();
            $fiveYearsAgo = Carbon::now()->subYears(5)->toDateString();

            // Get issuance count in past 3 years
            $issuanceData = DB::selectOne("
                SELECT
                    COUNT(*) as issuance_count,
                    COUNT(DISTINCT EXTRACT(YEAR FROM change_date)) as active_years
                FROM stock_share_changes_audit
                WHERE stock_id = ?
                  AND change_date >= ?
                  AND change_type = 'total_shares_outstanding'
                  AND new_value > old_value
                  AND ((new_value - old_value) / old_value::numeric * 100) >= 5.0
            ", [$stockId, $threeYearsAgo]);

            $issuanceCount = $issuanceData->issuance_count ?? 0;
            $activeYears = $issuanceData->active_years ?? 0;

            // Get cumulative dilution over past 5 years
            $dilutionData = DB::selectOne("
                WITH share_history AS (
                    SELECT
                        change_date,
                        old_value,
                        new_value,
                        ROW_NUMBER() OVER (ORDER BY change_date ASC) as rn
                    FROM stock_share_changes_audit
                    WHERE stock_id = ?
                      AND change_date >= ?
                      AND change_type = 'total_shares_outstanding'
                      AND change_date <= CURRENT_DATE
                    ORDER BY change_date ASC
                ),
                earliest_shares AS (
                    SELECT old_value as base_shares
                    FROM share_history
                    WHERE rn = 1
                    UNION ALL
                    SELECT ? as base_shares
                    WHERE NOT EXISTS (SELECT 1 FROM share_history)
                )
                SELECT
                    es.base_shares,
                    ? as current_shares,
                    CASE
                        WHEN es.base_shares > 0 THEN
                            ROUND(((? - es.base_shares) / es.base_shares::numeric * 100), 2)
                        ELSE 0
                    END as cumulative_dilution_pct
                FROM earliest_shares es
                LIMIT 1
            ", [$stockId, $fiveYearsAgo, $currentShares, $currentShares, $currentShares]);

            $cumulativeDilution = $dilutionData->cumulative_dilution_pct ?? 0.0;

            // Classification logic
            $isChronicIssuer = $issuanceCount > 2;
            $excessiveDilution = $cumulativeDilution > 30.0;

            // Determine dilution severity
            if ($cumulativeDilution >= 50.0) {
                $dilutionSeverity = 'extreme';
            } elseif ($cumulativeDilution >= 30.0) {
                $dilutionSeverity = 'high';
            } elseif ($cumulativeDilution >= 15.0) {
                $dilutionSeverity = 'moderate';
            } else {
                $dilutionSeverity = 'low';
            }

            // Recommendation logic
            if ($isChronicIssuer || $excessiveDilution) {
                $recommendation = 'bearish';
            } elseif ($cumulativeDilution < 10.0 && $issuanceCount <= 1) {
                $recommendation = 'neutral_bullish';
            } else {
                $recommendation = 'neutral';
            }

            return [
                'is_chronic_issuer' => $isChronicIssuer,
                'total_issuances_3yr' => $issuanceCount,
                'cumulative_dilution_5yr' => (float)$cumulativeDilution,
                'dilution_severity' => $dilutionSeverity,
                'recommendation' => $recommendation,
            ];

        } catch (\Exception $e) {
            return [
                'is_chronic_issuer' => false,
                'total_issuances_3yr' => 0,
                'cumulative_dilution_5yr' => 0.0,
                'dilution_severity' => 'unknown',
                'recommendation' => 'neutral',
            ];
        }
    }
}
