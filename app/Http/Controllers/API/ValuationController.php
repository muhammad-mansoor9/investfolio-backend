<?php

namespace App\Http\Controllers\API;

use App\Models\SavedValuation;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ValuationController extends BaseController
{
    /**
     * Calculate fair value without saving
     */
    public function calculate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|uuid',
                'stock_symbol' => 'required|string',
                'year_label' => 'required|string|max:20',
                'eps' => 'required|numeric|min:0.01',
                'revenue_growth' => 'nullable|numeric',
                'gross_profit' => 'nullable|numeric',
                'dps' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $stock = Stock::find($request->stock_id);
            if (!$stock) {
                return $this->sendError('Stock not found', [], 404);
            }

            $forecastEps = (float)$request->eps;

            // Get current price
            $latestPrice = DB::table('stock_prices')
                ->where('stock_id', $request->stock_id)
                ->orderByDesc('date')
                ->first(['close']);

            $currentPrice = $latestPrice ? (float)$latestPrice->close : 0;

            // Get TTM EPS, Revenue Growth, Gross Profit, and DPS from financial_results
            $ttmMetricsResult = DB::selectOne("
                WITH latest_quarters AS (
                    SELECT
                        (fr.data->>'eps')::numeric as eps_value,
                        (fr.data->>'revenue_growth')::numeric as revenue_growth_value,
                        (fr.data->>'gross_profit')::numeric as gross_profit_value,
                        (fr.data->>'dps')::numeric as dps_value
                    FROM financial_results fr
                    WHERE fr.stock_id = :stock_id
                      AND fr.period_type = 'quarterly'
                      AND fr.data->>'eps' IS NOT NULL
                    ORDER BY fr.period_name DESC
                    LIMIT 4
                )
                SELECT
                    COALESCE(SUM(eps_value), NULL) as ttm_eps,
                    COALESCE(AVG(revenue_growth_value), NULL) as avg_revenue_growth,
                    COALESCE(AVG(gross_profit_value), NULL) as avg_gross_profit,
                    COALESCE(SUM(dps_value), NULL) as ttm_dps
                FROM latest_quarters
            ", ['stock_id' => $request->stock_id]);

            $ttmEps = $ttmMetricsResult && $ttmMetricsResult->ttm_eps ? (float)$ttmMetricsResult->ttm_eps : null;
            $ttmRevenueGrowth = $ttmMetricsResult && $ttmMetricsResult->avg_revenue_growth ? (float)$ttmMetricsResult->avg_revenue_growth : null;
            $ttmGrossProfit = $ttmMetricsResult && $ttmMetricsResult->avg_gross_profit ? (float)$ttmMetricsResult->avg_gross_profit : null;
            $ttmDps = $ttmMetricsResult && $ttmMetricsResult->ttm_dps ? (float)$ttmMetricsResult->ttm_dps : null;

            // Calculate TTM dividend yield from TTM DPS
            $ttmDividendYield = null;
            if ($currentPrice > 0 && $ttmDps && $ttmDps > 0) {
                $ttmDividendYield = ($ttmDps / $currentPrice) * 100;
            }

            // Calculate sector PE
            $sectorPe = $this->calculateSectorPE($stock->sector_id);

            // Calculate PE multiples
            $forwardPe = $currentPrice > 0 && $forecastEps > 0 ? $currentPrice / $forecastEps : 0;
            $ttmPe = $ttmEps && $ttmEps > 0 ? $currentPrice / $ttmEps : null;
            $epsGrowthPct = $ttmEps && $ttmEps > 0 ? (($forecastEps - $ttmEps) / $ttmEps) * 100 : null;

            // Calculate three scenarios
            $scenarios = [
                [
                    'name' => 'Bear Case',
                    'pe_multiple' => round($forwardPe, 2),
                    'fair_value' => round($currentPrice, 2),
                    'upside_pct' => 0,
                    'explanation' => 'Stock stays at current price. No re-rating happens — investors pay today\'s Forward PE on forecast earnings.',
                    'color' => 'neutral',
                ],
                [
                    'name' => 'Base Case',
                    'pe_multiple' => round($sectorPe, 2),
                    'fair_value' => round($forecastEps * $sectorPe, 2),
                    'upside_pct' => $currentPrice > 0 ? round((($forecastEps * $sectorPe - $currentPrice) / $currentPrice) * 100, 2) : 0,
                    'explanation' => 'Fair value if stock re-rates to sector average PE. Realistic scenario assuming market values it like peers.',
                    'color' => 'blue',
                ],
                [
                    'name' => 'Bull Case',
                    'pe_multiple' => $ttmPe ? round($ttmPe, 2) : null,
                    'fair_value' => $ttmPe ? round($forecastEps * $ttmPe, 2) : null,
                    'upside_pct' => $ttmPe && $currentPrice > 0 ? round((($forecastEps * $ttmPe - $currentPrice) / $currentPrice) * 100, 2) : null,
                    'explanation' => 'Fair value if market continues paying the historical multiple (TTM PE). Upside if valuation expands or market re-rates higher.',
                    'color' => 'green',
                ],
            ];

            // Evaluate signals
            $signals = $this->evaluateSignals(
                (float)$request->revenue_growth,
                (float)$request->gross_profit,
                (float)$request->dps,
                $forecastEps,
                $ttmEps,
                $ttmRevenueGrowth,
                $ttmGrossProfit,
                $ttmDividendYield,
                $sectorPe,
                $forwardPe,
                $currentPrice
            );

            $signalScore = count(array_filter($signals));
            $outlook = $this->determineOutlook($signalScore);

            $results = [
                'current_price' => round($currentPrice, 2),
                'ttm_eps' => $ttmEps ? round($ttmEps, 2) : null,
                'ttm_pe' => $ttmPe ? round($ttmPe, 2) : null,
                'ttm_revenue_growth' => $ttmRevenueGrowth ? round($ttmRevenueGrowth, 2) : null,
                'ttm_gross_profit' => $ttmGrossProfit ? round($ttmGrossProfit, 2) : null,
                'ttm_dividend_yield' => $ttmDividendYield ? round($ttmDividendYield, 2) : null,
                'metrics' => [
                    'forward_pe' => round($forwardPe, 2),
                    'sector_pe' => round($sectorPe, 2),
                    'eps_growth_pct' => $epsGrowthPct ? round($epsGrowthPct, 2) : null,
                    'is_forward_pe_cheap' => $forwardPe < $sectorPe,
                ],
                'scenarios' => $scenarios,
                'signals' => $signals,
                'outlook' => $outlook,
                'signal_score' => $signalScore,
            ];

            return $this->sendResponse($results, 'Valuation calculated successfully');

        } catch (\Exception $e) {
            return $this->sendError('Calculation failed', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save a valuation
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'stock_id' => 'required|uuid',
                'stock_symbol' => 'required|string',
                'year_label' => 'required|string|max:20',
                'eps' => 'required|numeric|min:0.01',
                'revenue_growth' => 'nullable|numeric',
                'gross_profit' => 'nullable|numeric',
                'dps' => 'nullable|numeric',
                'current_price' => 'required|numeric',
                'sector_pe' => 'required|numeric',
                'fair_value' => 'required|numeric',
                'upside_pct' => 'required|numeric',
                'outlook' => 'required|in:Bullish,Neutral,Bearish',
                'signal_score' => 'required|integer|min:0|max:4',
                'signals' => 'required|array',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            if (!$user) {
                return $this->sendError('Unauthorized', [], 401);
            }

            $saved = SavedValuation::create([
                'user_id' => $user->id,
                'stock_id' => $request->stock_id,
                'stock_symbol' => $request->stock_symbol,
                'name' => $request->name,
                'year_label' => $request->year_label,
                'eps' => $request->eps,
                'revenue_growth' => $request->revenue_growth,
                'gross_profit' => $request->gross_profit,
                'dps' => $request->dps,
                'current_price' => $request->current_price,
                'sector_pe' => $request->sector_pe,
                'fair_value' => $request->fair_value,
                'upside_pct' => $request->upside_pct,
                'outlook' => $request->outlook,
                'signal_score' => $request->signal_score,
                'signals' => $request->signals,
            ]);

            return $this->sendResponse($saved, 'Valuation saved successfully');

        } catch (\Exception $e) {
            return $this->sendError('Save failed', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get saved valuations for user
     */
    public function getSaved(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return $this->sendError('Unauthorized', [], 401);
            }

            $valuations = SavedValuation::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get();

            return $this->sendResponse(
                [
                    'saved_valuations' => $valuations,
                    'total_count' => count($valuations),
                ],
                'Saved valuations retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->sendError('Retrieval failed', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a saved valuation
     */
    public function delete(Request $request, $id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return $this->sendError('Unauthorized', [], 401);
            }

            $valuation = SavedValuation::findOrFail($id);

            if ($valuation->user_id !== $user->id) {
                return $this->sendError('Forbidden', [], 403);
            }

            $valuation->delete();

            return $this->sendResponse([], 'Valuation deleted successfully');

        } catch (\Exception $e) {
            return $this->sendError('Deletion failed', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate sector PE from top 6 companies by market cap
     */
    private function calculateSectorPE($sectorId): float
    {
        try {
            $query = "
            WITH top_6 AS (
                SELECT
                    s.id,
                    s.symbol,
                    s.market_cap,
                    ROW_NUMBER() OVER (ORDER BY s.market_cap DESC NULLS LAST) as cap_rank
                FROM stocks s
                WHERE s.sector_id = :sector_id
                  AND s.is_active = true
                  AND s.market_cap > 0
            ),
            filtered_top_6 AS (
                SELECT *
                FROM top_6
                WHERE cap_rank <= 6
            ),
            latest_prices AS (
                SELECT
                    ft.id,
                    sp.close as price
                FROM filtered_top_6 ft
                LEFT JOIN stock_prices sp ON ft.id = sp.stock_id
                    AND sp.date = (SELECT MAX(date) FROM stock_prices sp2 WHERE sp2.stock_id = sp.stock_id)
            ),
            latest_eps AS (
                SELECT
                    ft.id,
                    (fr.data->>'eps')::numeric as eps_value,
                    ROW_NUMBER() OVER (PARTITION BY ft.id ORDER BY fr.created_at DESC) as rn
                FROM filtered_top_6 ft
                LEFT JOIN financial_results fr ON ft.id = fr.stock_id
                    AND fr.period_type = 'quarterly'
                    AND fr.data->>'eps' IS NOT NULL
            ),
            ttm_eps AS (
                SELECT
                    ft.id,
                    COALESCE(SUM(le.eps_value), 0) as ttm_eps
                FROM filtered_top_6 ft
                LEFT JOIN latest_eps le ON ft.id = le.id AND le.rn <= 4
                GROUP BY ft.id
            ),
            pe_calc AS (
                SELECT
                    CASE
                        WHEN COALESCE(lp.price, 0) > 0 AND te.ttm_eps > 0
                            THEN (lp.price / te.ttm_eps)
                        ELSE NULL
                    END as pe_ratio
                FROM filtered_top_6 ft
                LEFT JOIN latest_prices lp ON ft.id = lp.id
                LEFT JOIN ttm_eps te ON ft.id = te.id
            )
            SELECT
                ROUND(AVG(pe_ratio)::numeric, 2) as avg_pe
            FROM pe_calc
            WHERE pe_ratio IS NOT NULL
            ";

            $result = DB::selectOne($query, ['sector_id' => $sectorId]);

            return $result && $result->avg_pe ? (float)$result->avg_pe : 0;

        } catch (\Exception $e) {
            \Log::warning('Error calculating sector PE: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Evaluate signals
     */
    private function evaluateSignals(
        float $forecastRevenueGrowth,
        float $forecastGrossProfit,
        float $forecastDividendYield,
        float $forecastEps,
        ?float $ttmEps,
        ?float $ttmRevenueGrowth,
        ?float $ttmGrossProfit,
        ?float $ttmDividendYield,
        float $sectorPe,
        float $forwardPe,
        float $currentPrice
    ): array {
        $signals = [];

        // EPS Growth signal: Forecast EPS > TTM EPS (if TTM available)
        if ($ttmEps && $ttmEps > 0) {
            $signals['eps_growth'] = $forecastEps > $ttmEps;
        }

        // Revenue growth signal: Forecast revenue growth > TTM revenue growth (if TTM available)
        // Fall back to >= 15% threshold if TTM not available
        if ($ttmRevenueGrowth !== null && $ttmRevenueGrowth > 0) {
            $signals['revenue_growth'] = $forecastRevenueGrowth > $ttmRevenueGrowth;
        } else {
            $signals['revenue_growth'] = $forecastRevenueGrowth >= 15;
        }

        // Gross margin signal: Forecast gross profit > TTM gross profit (if TTM available)
        // Fall back to >= 20% threshold if TTM not available
        if ($ttmGrossProfit !== null && $ttmGrossProfit > 0) {
            $signals['gross_margin'] = $forecastGrossProfit > $ttmGrossProfit;
        } else {
            $signals['gross_margin'] = $forecastGrossProfit >= 20;
        }

        // Dividend yield signal: Forecast dividend yield > TTM dividend yield (if available and non-zero forecast)
        if ($forecastDividendYield > 0) {
            if ($ttmDividendYield !== null && $ttmDividendYield > 0) {
                $signals['dividend_yield'] = $forecastDividendYield > $ttmDividendYield;
            } else {
                $signals['dividend_yield'] = $forecastDividendYield >= 3;
            }
        }

        // Forward PE cheap signal: Forward PE < Sector PE
        $signals['forward_pe_cheap'] = $sectorPe > 0 && $forwardPe < $sectorPe;

        return $signals;
    }

    /**
     * Determine outlook based on signal score
     */
    private function determineOutlook(int $signalScore): string
    {
        if ($signalScore >= 3) {
            return 'Bullish';
        } elseif ($signalScore === 2) {
            return 'Neutral';
        } else {
            return 'Bearish';
        }
    }
}
