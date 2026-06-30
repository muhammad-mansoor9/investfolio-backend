<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockRetracementController extends Controller
{
    /**
     * Get stock retracement analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStockRetracement(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:52week-high,52week-low,all-time-high,custom',
                'date' => 'required_if:type,custom|date',
                'exclude_current_month' => 'boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->get('type');
            $customDate = $request->get('date');
            $excludeCurrentMonth = $request->boolean('exclude_current_month', false);

            switch ($type) {
                case '52week-high':
                    return $this->get52WeekHighRetracement($excludeCurrentMonth);
                case '52week-low':
                    return $this->get52WeekLowRetracement($excludeCurrentMonth);
                case 'all-time-high':
                    return $this->getAllTimeHighRetracement($excludeCurrentMonth);
                case 'custom':
                    return $this->getCustomDateRetracement($customDate);
                default:
                    return $this->validationErrorResponse(['type' => 'Invalid comparison type']);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving retracement data', $e);
        }
    }

    /**
     * Get the comparison end date based on exclude_current_month flag
     */
    private function getComparisonEndDate(bool $excludeCurrentMonth): string
    {
        if ($excludeCurrentMonth) {
            // Get the last day of the previous month
            return "
                (DATE_TRUNC('month', (SELECT MAX(date) FROM stock_prices))::date - INTERVAL '1 day')::date
            ";
        } else {
            // Use the latest available date
            return "(SELECT MAX(date) FROM stock_prices)";
        }
    }

    /**
     * Get 52-week high retracement
     *
     * @param bool $excludeCurrentMonth
     * @return JsonResponse
     */
    private function get52WeekHighRetracement(bool $excludeCurrentMonth = false): JsonResponse
    {
        $comparisonEndDate = $this->getComparisonEndDate($excludeCurrentMonth);

        $query = "
        WITH comparison_end_date AS (
            SELECT {$comparisonEndDate} as end_date
        ),
        latest_date AS (
            SELECT MAX(date) as max_date
            FROM stock_prices
        ),
        week_52_ago AS (
            SELECT (SELECT end_date FROM comparison_end_date) - INTERVAL '52 weeks' as date_52w_ago
        ),
        current_prices AS (
            SELECT
                sp.stock_id,
                sp.close as current_price,
                sp.date as current_date
            FROM stock_prices sp
            CROSS JOIN latest_date ld
            WHERE sp.date = ld.max_date
        ),
        high_52_week_prices AS (
            SELECT
                sp.stock_id,
                MAX(sp.high) as high_52w
            FROM stock_prices sp
            CROSS JOIN comparison_end_date ced
            CROSS JOIN week_52_ago w52
            WHERE sp.date BETWEEN w52.date_52w_ago AND ced.end_date
            GROUP BY sp.stock_id
        ),
        high_52_week AS (
            SELECT DISTINCT ON (sp.stock_id)
                sp.stock_id,
                h52p.high_52w,
                sp.date as high_date
            FROM stock_prices sp
            INNER JOIN high_52_week_prices h52p ON sp.stock_id = h52p.stock_id AND sp.high = h52p.high_52w
            CROSS JOIN comparison_end_date ced
            CROSS JOIN week_52_ago w52
            WHERE sp.date BETWEEN w52.date_52w_ago AND ced.end_date
            ORDER BY sp.stock_id, sp.date DESC
        )
        SELECT
            s.symbol,
            s.description as name,
            cp.current_price,
            h52.high_52w as comparison_price,
            (cp.current_price - h52.high_52w) as difference,
            CASE
                WHEN h52.high_52w > 0 THEN
                    ROUND((((cp.current_price - h52.high_52w) / h52.high_52w) * 100)::numeric, 2)
                ELSE NULL
            END as difference_percent,
            h52.high_date as comparison_date
        FROM stocks s
        INNER JOIN current_prices cp ON s.id = cp.stock_id
        INNER JOIN high_52_week h52 ON s.id = h52.stock_id
        WHERE s.is_active = true
          AND s.market_cap > 0
          AND cp.current_price IS NOT NULL
          AND h52.high_52w IS NOT NULL
          AND h52.high_52w > 0
        ORDER BY difference_percent ASC
        ";

        try {
            $results = DB::select($query);

            $stocks = collect($results)->map(function ($row) {
                return [
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'currentPrice' => (float) $row->current_price,
                    'comparisonPrice' => (float) $row->comparison_price,
                    'difference' => (float) $row->difference,
                    'differencePercent' => $row->difference_percent ? (float) $row->difference_percent : null,
                    'comparisonDate' => $row->comparison_date,
                ];
            });

            return $this->successResponse([
                'stocks' => $stocks,
                'comparisonType' => '52week-high',
                'excludeCurrentMonth' => $excludeCurrentMonth,
                'totalStocks' => $stocks->count(),
            ], 'Stock retracement data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve 52-week high retracement', $e);
        }
    }

    /**
     * Get 52-week low retracement
     *
     * @param bool $excludeCurrentMonth
     * @return JsonResponse
     */
    private function get52WeekLowRetracement(bool $excludeCurrentMonth = false): JsonResponse
    {
        $comparisonEndDate = $this->getComparisonEndDate($excludeCurrentMonth);

        $query = "
        WITH comparison_end_date AS (
            SELECT {$comparisonEndDate} as end_date
        ),
        latest_date AS (
            SELECT MAX(date) as max_date
            FROM stock_prices
        ),
        week_52_ago AS (
            SELECT (SELECT end_date FROM comparison_end_date) - INTERVAL '52 weeks' as date_52w_ago
        ),
        current_prices AS (
            SELECT
                sp.stock_id,
                sp.close as current_price,
                sp.date as current_date
            FROM stock_prices sp
            CROSS JOIN latest_date ld
            WHERE sp.date = ld.max_date
        ),
        low_52_week_prices AS (
            SELECT
                sp.stock_id,
                MIN(sp.low) as low_52w
            FROM stock_prices sp
            CROSS JOIN comparison_end_date ced
            CROSS JOIN week_52_ago w52
            WHERE sp.date BETWEEN w52.date_52w_ago AND ced.end_date
            GROUP BY sp.stock_id
        ),
        low_52_week AS (
            SELECT DISTINCT ON (sp.stock_id)
                sp.stock_id,
                l52p.low_52w,
                sp.date as low_date
            FROM stock_prices sp
            INNER JOIN low_52_week_prices l52p ON sp.stock_id = l52p.stock_id AND sp.low = l52p.low_52w
            CROSS JOIN comparison_end_date ced
            CROSS JOIN week_52_ago w52
            WHERE sp.date BETWEEN w52.date_52w_ago AND ced.end_date
            ORDER BY sp.stock_id, sp.date DESC
        )
        SELECT
            s.symbol,
            s.description as name,
            cp.current_price,
            l52.low_52w as comparison_price,
            (cp.current_price - l52.low_52w) as difference,
            CASE
                WHEN l52.low_52w > 0 THEN
                    ROUND((((cp.current_price - l52.low_52w) / l52.low_52w) * 100)::numeric, 2)
                ELSE NULL
            END as difference_percent,
            l52.low_date as comparison_date
        FROM stocks s
        INNER JOIN current_prices cp ON s.id = cp.stock_id
        INNER JOIN low_52_week l52 ON s.id = l52.stock_id
        WHERE s.is_active = true
          AND s.market_cap > 0
          AND cp.current_price IS NOT NULL
          AND l52.low_52w IS NOT NULL
          AND l52.low_52w > 0
        ORDER BY difference_percent DESC
        ";

        try {
            $results = DB::select($query);

            $stocks = collect($results)->map(function ($row) {
                return [
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'currentPrice' => (float) $row->current_price,
                    'comparisonPrice' => (float) $row->comparison_price,
                    'difference' => (float) $row->difference,
                    'differencePercent' => $row->difference_percent ? (float) $row->difference_percent : null,
                    'comparisonDate' => $row->comparison_date,
                ];
            });

            return $this->successResponse([
                'stocks' => $stocks,
                'comparisonType' => '52week-low',
                'excludeCurrentMonth' => $excludeCurrentMonth,
                'totalStocks' => $stocks->count(),
            ], 'Stock retracement data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve 52-week low retracement', $e);
        }
    }

    /**
     * Get all-time high retracement
     *
     * @param bool $excludeCurrentMonth
     * @return JsonResponse
     */
    private function getAllTimeHighRetracement(bool $excludeCurrentMonth = false): JsonResponse
    {
        $comparisonEndDate = $this->getComparisonEndDate($excludeCurrentMonth);

        $query = "
        WITH comparison_end_date AS (
            SELECT {$comparisonEndDate} as end_date
        ),
        latest_date AS (
            SELECT MAX(date) as max_date
            FROM stock_prices
        ),
        current_prices AS (
            SELECT
                sp.stock_id,
                sp.close as current_price,
                sp.date as current_date
            FROM stock_prices sp
            CROSS JOIN latest_date ld
            WHERE sp.date = ld.max_date
        ),
        all_time_high_prices AS (
            SELECT
                sp.stock_id,
                MAX(sp.high) as ath_price
            FROM stock_prices sp
            CROSS JOIN comparison_end_date ced
            WHERE sp.date <= ced.end_date
            GROUP BY sp.stock_id
        ),
        all_time_high AS (
            SELECT DISTINCT ON (sp.stock_id)
                sp.stock_id,
                athp.ath_price,
                sp.date as ath_date
            FROM stock_prices sp
            INNER JOIN all_time_high_prices athp ON sp.stock_id = athp.stock_id AND sp.high = athp.ath_price
            CROSS JOIN comparison_end_date ced
            WHERE sp.date <= ced.end_date
            ORDER BY sp.stock_id, sp.date DESC
        )
        SELECT
            s.symbol,
            s.description as name,
            cp.current_price,
            ath.ath_price as comparison_price,
            (cp.current_price - ath.ath_price) as difference,
            CASE
                WHEN ath.ath_price > 0 THEN
                    ROUND((((cp.current_price - ath.ath_price) / ath.ath_price) * 100)::numeric, 2)
                ELSE NULL
            END as difference_percent,
            ath.ath_date as comparison_date
        FROM stocks s
        INNER JOIN current_prices cp ON s.id = cp.stock_id
        INNER JOIN all_time_high ath ON s.id = ath.stock_id
        WHERE s.is_active = true
          AND s.market_cap > 0
          AND cp.current_price IS NOT NULL
          AND ath.ath_price IS NOT NULL
          AND ath.ath_price > 0
        ORDER BY difference_percent ASC
        ";

        try {
            $results = DB::select($query);

            $stocks = collect($results)->map(function ($row) {
                return [
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'currentPrice' => (float) $row->current_price,
                    'comparisonPrice' => (float) $row->comparison_price,
                    'difference' => (float) $row->difference,
                    'differencePercent' => $row->difference_percent ? (float) $row->difference_percent : null,
                    'comparisonDate' => $row->comparison_date,
                ];
            });

            return $this->successResponse([
                'stocks' => $stocks,
                'comparisonType' => 'all-time-high',
                'excludeCurrentMonth' => $excludeCurrentMonth,
                'totalStocks' => $stocks->count(),
            ], 'Stock retracement data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve all-time high retracement', $e);
        }
    }

    /**
     * Get custom date retracement
     *
     * @param string $customDate
     * @return JsonResponse
     */
    private function getCustomDateRetracement($customDate): JsonResponse
    {
        $query = "
        WITH latest_date AS (
            SELECT MAX(date) as max_date
            FROM stock_prices
        ),
        current_prices AS (
            SELECT
                sp.stock_id,
                sp.close as current_price,
                sp.date as current_date
            FROM stock_prices sp
            CROSS JOIN latest_date ld
            WHERE sp.date = ld.max_date
        ),
        custom_date_prices AS (
            SELECT
                sp.stock_id,
                sp.close as custom_price,
                sp.date as custom_date
            FROM stock_prices sp
            WHERE sp.date = :custom_date
        )
        SELECT
            s.symbol,
            s.description as name,
            cp.current_price,
            cdp.custom_price as comparison_price,
            (cp.current_price - cdp.custom_price) as difference,
            CASE
                WHEN cdp.custom_price > 0 THEN
                    ROUND((((cp.current_price - cdp.custom_price) / cdp.custom_price) * 100)::numeric, 2)
                ELSE NULL
            END as difference_percent,
            cdp.custom_date as comparison_date
        FROM stocks s
        INNER JOIN current_prices cp ON s.id = cp.stock_id
        INNER JOIN custom_date_prices cdp ON s.id = cdp.stock_id
        WHERE s.is_active = true
          AND s.market_cap > 0
          AND cp.current_price IS NOT NULL
          AND cdp.custom_price IS NOT NULL
          AND cdp.custom_price > 0
        ORDER BY difference_percent DESC
        ";

        try {
            $results = DB::select($query, ['custom_date' => $customDate]);

            $stocks = collect($results)->map(function ($row) {
                return [
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'currentPrice' => (float) $row->current_price,
                    'comparisonPrice' => (float) $row->comparison_price,
                    'difference' => (float) $row->difference,
                    'differencePercent' => $row->difference_percent ? (float) $row->difference_percent : null,
                    'comparisonDate' => $row->comparison_date,
                ];
            });

            return $this->successResponse([
                'stocks' => $stocks,
                'comparisonType' => 'custom',
                'comparisonDate' => $customDate,
                'totalStocks' => $stocks->count(),
            ], 'Stock retracement data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve custom date retracement', $e);
        }
    }
}
