<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\MansoorSpecialFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockController extends Controller
{
    /**
     * Get list of stocks for dropdown
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllStocks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'sometimes|string|max:255',
                'limit' => 'sometimes|integer|min:1|max:500',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $searchQuery = $request->get('search', '');
            $limit = $request->get('limit', 500);

            $query = DB::table('stocks as s')
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                ->select([
                    's.id',
                    's.symbol',
                    's.description',
                    's.is_shariah',
                    's.market_cap',
                    'sec.id as sector_id',
                    'sec.name as sector_name',
                ])
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0);

            if (!empty($searchQuery)) {
                $query->where(function ($q) use ($searchQuery) {
                    $q->where('s.symbol', 'ILIKE', '%' . $searchQuery . '%')
                        ->orWhere('s.description', 'ILIKE', '%' . $searchQuery . '%');
                });
            }

            $stocks = $query
                ->orderBy('s.symbol', 'asc')
                ->limit($limit)
                ->get();

            $formattedStocks = $stocks->map(function ($stock) {
                return [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'is_shariah' => $stock->is_shariah,
                    'market_cap' => $stock->market_cap,
                    'sector' => $stock->sector_id ? [
                        'id' => $stock->sector_id,
                        'name' => $stock->sector_name
                    ] : null,
                ];
            });

            return $this->successResponse([
                'stocks' => $formattedStocks,
                'total_results' => $formattedStocks->count()
            ], 'Stocks retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stocks', $e);
        }
    }

    /**
     * Search stocks by string (symbol or description)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function searchStocks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:1|max:255',
                'limit' => 'sometimes|integer|min:1|max:100',
                'is_shariah' => 'sometimes|boolean',
                'sector' => 'sometimes|string|max:255'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $searchQuery = trim($request->get('query'));
            $limit = $request->get('limit', 20);
            $searchQueryEscaped = strtoupper(addslashes($searchQuery));

            // Build the search query
            $query = DB::table('stocks as s')
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                ->leftJoin('exchange_markets as em', 's.exchange_id', '=', 'em.id')
                ->leftJoin('stock_prices as sp', function ($join) {
                    $join->on('s.id', '=', 'sp.stock_id')
                        ->whereRaw('sp.date = (SELECT MAX(date) FROM stock_prices WHERE stock_id = s.id)');
                })
                ->select([
                    's.id',
                    's.symbol',
                    's.description',
                    's.is_shariah',
                    's.is_active',
                    's.market_cap',
                    's.total_shares_outstanding',
                    's.free_float',
                    's.face_value',
                    DB::raw('ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_percentage'),
                    'sec.id as sector_id',
                    'sec.name as sector_name',
                    'em.id as exchange_id',
                    'em.name as exchange_name',
                    'sp.id as latest_price_id',
                    'sp.price as latest_price',
                    'sp.open as latest_open',
                    'sp.high as latest_high',
                    'sp.low as latest_low',
                    'sp.close as latest_close',
                    'sp.volume as latest_volume',
                    'sp.change as latest_change',
                    'sp.date as latest_price_date',
                    DB::raw("
                    CASE
                        WHEN UPPER(s.symbol) = '{$searchQueryEscaped}' THEN 100
                        WHEN UPPER(s.symbol) LIKE '{$searchQueryEscaped}%' THEN 90
                        WHEN UPPER(s.description) LIKE '{$searchQueryEscaped}%' THEN 80
                        WHEN UPPER(s.description) LIKE '%{$searchQueryEscaped}%' THEN 70
                        ELSE 60
                    END as relevance_score
                ")
                ])
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0)
                ->where(function ($q) use ($searchQuery) {
                    $q->where('s.symbol', 'ILIKE', '%' . $searchQuery . '%')
                        ->orWhere('s.description', 'ILIKE', '%' . $searchQuery . '%');
                })
                ->orderByRaw('relevance_score DESC, s.market_cap DESC NULLS LAST')
                ->limit($limit);

            // Apply additional filters
            if ($request->has('is_shariah')) {
                $query->where('s.is_shariah', $request->boolean('is_shariah'));
            }

            if ($request->has('sector')) {
                $query->where('sec.name', 'ILIKE', '%' . $request->get('sector') . '%');
            }

            $stocks = $query->get();

            // Remove relevance_score from results
            $stocks = $stocks->map(function ($stock) {
                unset($stock->relevance_score);
                return $stock;
            });

            // Structure stocks response consistently
            $structuredStocks = $stocks->map(function ($stock) {
                return [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'is_shariah' => $stock->is_shariah,
                    'is_active' => $stock->is_active,
                    'market_cap' => $stock->market_cap,
                    'total_shares_outstanding' => $stock->total_shares_outstanding,
                    'free_float' => $stock->free_float,
                    'free_float_percentage' => $stock->free_float_percentage,
                    'face_value' => $stock->face_value,
                    'sector' => $stock->sector_id ? [
                        'id' => $stock->sector_id,
                        'name' => $stock->sector_name
                    ] : null,
                    'exchange' => $stock->exchange_id ? [
                        'id' => $stock->exchange_id,
                        'name' => $stock->exchange_name
                    ] : null,
                    'latest_price' => $stock->latest_price_id ? [
                        'price' => $stock->latest_price,
                        'open' => $stock->latest_open,
                        'high' => $stock->latest_high,
                        'low' => $stock->latest_low,
                        'close' => $stock->latest_close,
                        'volume' => $stock->latest_volume,
                        'change' => $stock->latest_change,
                        'date' => $stock->latest_price_date
                    ] : null
                ];
            });

            return $this->successResponse([
                'stocks' => $structuredStocks,
                'search_query' => $searchQuery,
                'total_results' => $structuredStocks->count()
            ], 'Stocks search completed successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while searching stocks', $e);
        }
    }

    /**
     * Get detailed stock information including sector, indices, and latest price
     *
     * @param Request $request
     * @param string $stockId
     * @return JsonResponse
     */
    public function getStockDetails(Request $request, string $stockId): JsonResponse
    {
        try {
            // Get basic stock information with relationships
            $stock = DB::table('stocks as s')
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                ->leftJoin('exchange_markets as em', 's.exchange_id', '=', 'em.id')
                ->leftJoin('assets as a', 's.asset_id', '=', 'a.id')
                ->leftJoin('stock_prices as sp', function ($join) {
                    $join->on('s.id', '=', 'sp.stock_id')
                        ->whereRaw('sp.date = (SELECT MAX(date) FROM stock_prices WHERE stock_id = s.id)');
                })
                ->select([
                    's.id',
                    's.symbol',
                    's.description',
                    's.is_shariah',
                    's.is_active',
                    's.market_cap',
                    's.total_shares_outstanding',
                    's.free_float',
                    's.face_value',
                    DB::raw('ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_percentage'),
                    's.created_at',
                    's.updated_at',
                    // Sector information
                    'sec.id as sector_id',
                    'sec.name as sector_name',
                    // Exchange information
                    'em.id as exchange_id',
                    'em.name as exchange_name',
                    // Asset information
                    'a.id as asset_id',
                    'a.name as asset_name',
                    'a.type as asset_type',
                    // Latest price information
                    'sp.id as latest_price_id',
                    'sp.price as latest_price',
                    'sp.open as latest_open',
                    'sp.high as latest_high',
                    'sp.low as latest_low',
                    'sp.close as latest_close',
                    'sp.volume as latest_volume',
                    'sp.change as latest_change',
                    'sp.date as latest_price_date'
                ])
                ->where('s.id', $stockId)
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0)
                ->first();

            if (!$stock) {
                return $this->notFoundResponse('Stock not found');
            }

            // Get indices information
            $indices = DB::table('index_stocks as idx_s')
                ->join('indices as idx', 'idx_s.index_id', '=', 'idx.id')
                ->select([
                    'idx.id',
                    'idx.symbol',
                    'idx.description',
                    'idx_s.weightage',
                    'idx_s.created_at as added_date'
                ])
                ->where('idx_s.stock_id', $stockId)
                ->orderBy('idx_s.weightage', 'desc')
                ->get();

            // Get recent price history (last 30 days)
            $priceHistory = DB::table('stock_prices')
                ->select([
                    'date',
                    'open',
                    'high',
                    'low',
                    'close',
                    'volume',
                    'change'
                ])
                ->where('stock_id', $stockId)
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();

            // Structure the response
            $response = [
                'stock' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'is_shariah' => $stock->is_shariah,
                    'is_active' => $stock->is_active,
                    'market_cap' => $stock->market_cap,
                    'total_shares_outstanding' => $stock->total_shares_outstanding,
                    'free_float' => $stock->free_float,
                    'free_float_percentage' => $stock->free_float_percentage,
                    'face_value' => $stock->face_value,
                    'sector' => $stock->sector_id ? [
                        'id' => $stock->sector_id,
                        'name' => $stock->sector_name,
                    ] : null,
                    'exchange' => $stock->exchange_id ? [
                        'id' => $stock->exchange_id,
                        'name' => $stock->exchange_name,
                    ] : null,
                    'asset' => $stock->asset_id ? [
                        'id' => $stock->asset_id,
                        'name' => $stock->asset_name,
                        'type' => $stock->asset_type
                    ] : null,
                    'latest_price' => $stock->latest_price_id ? [
                        'price' => $stock->latest_price,
                        'open' => $stock->latest_open,
                        'high' => $stock->latest_high,
                        'low' => $stock->latest_low,
                        'close' => $stock->latest_close,
                        'volume' => $stock->latest_volume,
                        'change' => $stock->latest_change,
                        'date' => $stock->latest_price_date
                    ] : null,
                    'indices' => $indices->toArray(),
                    'price_history' => $priceHistory->toArray()
                ],
            ];

            return $this->successResponse($response, 'Stock details retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock details', $e);
        }
    }

    /**
     * Get stock details by symbol (alternative endpoint)
     *
     * @param Request $request
     * @param string $symbol
     * @return JsonResponse
     */
    public function getStockDetailsBySymbol(Request $request, string $symbol): JsonResponse
    {
        try {
            // Find stock by symbol
            $stockId = DB::table('stocks')
                ->where('symbol', 'ILIKE', $symbol)
                ->where('is_active', true)
                ->where('market_cap', '>', 0)
                ->value('id');

            if (!$stockId) {
                return $this->notFoundResponse('Stock with symbol "' . $symbol . '" not found');
            }

            // Use existing method to get details
            return $this->getStockDetails($request, $stockId);

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock details', $e);
        }
    }

    /**
     * Get stocks with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStocks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'shariah_only' => 'sometimes|boolean',
                'index_id' => 'sometimes|uuid',
                'mansoor_special' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $shariahOnly = $request->boolean('shariah_only', false);
            $indexId = $request->get('index_id');
            $mansoorSpecial = $request->boolean('mansoor_special', false);

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                if (!$mansoorService->isAuthorized($request)) {
                    return $mansoorService->getAuthorizationError();
                }
            }

            $query = DB::table('stocks as s')
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                ->leftJoin('stock_prices as sp', function ($join) {
                    $join->on('s.id', '=', 'sp.stock_id')
                        ->whereRaw('sp.date = (SELECT MAX(date) FROM stock_prices WHERE stock_id = s.id)');
                });

            if ($indexId) {
                $query->join('index_stocks as idx_s', 's.id', '=', 'idx_s.stock_id')
                    ->where('idx_s.index_id', $indexId);
            }

            $query->select([
                's.id as stock_id',
                's.symbol',
                's.description as company_name',
                's.total_shares_outstanding',
                's.free_float',
                's.is_shariah',
                's.market_cap',
                's.year_ending',
                'sec.id as sector_id',
                'sec.name as sector_name',
                'sp.price as latest_price',
                DB::raw('CASE WHEN s.total_shares_outstanding > 0 THEN ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) ELSE 0 END AS free_float_percent')
            ])
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0);

            if ($shariahOnly || $mansoorSpecial) {
                $query->where('s.is_shariah', true);
            }

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                $query->whereRaw($mansoorService->getStocksWhereClause());
            }

            $stocks = $query
                ->orderBy('s.symbol', 'asc')
                ->get();

            $stockIds = $stocks->pluck('stock_id')->toArray();

            $indexTags = [];
            if (count($stockIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($stockIds), '?'));
                $indexData = DB::select("
                    SELECT
                        idx_s.stock_id,
                        JSON_AGG(JSON_BUILD_OBJECT('id', idx.id, 'name', idx.symbol)) AS tags
                    FROM index_stocks idx_s
                    JOIN indices idx ON idx_s.index_id = idx.id
                    WHERE idx_s.stock_id IN ($placeholders)
                    GROUP BY idx_s.stock_id
                ", $stockIds);

                foreach ($indexData as $item) {
                    $indexTags[$item->stock_id] = json_decode($item->tags, true) ?? [];
                }
            }

            $data = $stocks->map(function ($stock) use ($indexTags) {
                return [
                    'stock_id' => $stock->stock_id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector_id' => $stock->sector_id,
                    'sector_name' => $stock->sector_name,
                    'is_shariah' => $stock->is_shariah,
                    'market_cap' => $stock->market_cap,
                    'year_ending' => $stock->year_ending,
                    'total_shares_outstanding' => $stock->total_shares_outstanding,
                    'free_float' => $stock->free_float,
                    'free_float_percent' => $stock->free_float_percent,
                    'latest_price' => $stock->latest_price,
                    'index_tags' => $indexTags[$stock->stock_id] ?? []
                ];
            });

            return $this->successResponse([
                'total_results' => $data->count(),
                'data' => $data
            ], 'Stocks retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stocks', $e);
        }
    }

    /**
     * Get all stock prices for a given date (soft-date: MAX(date) <= requested date).
     * Returns the resolved date plus OHLCV + change data for every active stock
     * that has a price record on or before the requested date.
     *
     * Query param: date (YYYY-MM-DD, optional — defaults to latest available date)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStockPricesByDate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date'         => 'sometimes|date_format:Y-m-d',
                'start_date'   => 'sometimes|date_format:Y-m-d',
                'end_date'     => 'sometimes|date_format:Y-m-d|after_or_equal:start_date',
                'shariah_only' => 'sometimes|boolean',
                'mansoor_special' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $shariahOnly = $request->boolean('shariah_only', false);
            $mansoorSpecial = $request->boolean('mansoor_special', false);

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                if (!$mansoorService->isAuthorized($request)) {
                    return $mansoorService->getAuthorizationError();
                }
            }

            // Support legacy `date` param and new `start_date`/`end_date` params
            $rawEnd   = $request->get('end_date')   ?? $request->get('date');
            $rawStart = $request->get('start_date') ?? $request->get('date');

            // Resolve end date: MAX(date) <= requested (or absolute MAX if none given)
            $resolvedEnd = $rawEnd
                ? DB::table('stock_prices')->where('date', '<=', $rawEnd)->max('date')
                : DB::table('stock_prices')->max('date');

            if (!$resolvedEnd) {
                return $this->successResponse([
                    'date'                  => null,
                    'start_date'            => null,
                    'end_date'              => null,
                    'trading_days_in_range' => 0,
                    'total_results'         => 0,
                    'data'                  => [],
                ], 'No stock price data found');
            }

            // If the latest available date is before the requested start, the whole
            // range has no data — don't silently fall back to an earlier date.
            if ($rawStart && $resolvedEnd < $rawStart) {
                return $this->successResponse([
                    'date'                  => null,
                    'start_date'            => null,
                    'end_date'              => null,
                    'trading_days_in_range' => 0,
                    'total_results'         => 0,
                    'data'                  => [],
                ], 'No stock price data available for the selected period');
            }

            // Resolve start date: MIN(date) >= requested, capped at resolvedEnd
            $resolvedStart = $rawStart
                ? (DB::table('stock_prices')
                    ->where('date', '>=', $rawStart)
                    ->where('date', '<=', $resolvedEnd)
                    ->min('date') ?? $resolvedEnd)
                : $resolvedEnd;

            // Count distinct trading days in resolved range
            $tradingDaysInRange = (int) DB::selectOne(
                "SELECT COUNT(DISTINCT date) AS cnt FROM stock_prices WHERE date BETWEEN ? AND ?",
                [$resolvedStart, $resolvedEnd]
            )->cnt;

            $shariahFilter = $shariahOnly || $mansoorSpecial ? 'AND s.is_shariah = true' : '';

            $mansoorFilter = '';
            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                $mansoorFilter = 'AND ' . $mansoorService->getStockPricesWhereClause();
            }

            $rows = collect(DB::select("
                WITH
                stock_bounds AS (
                    SELECT
                        stock_id,
                        MIN(date) AS first_date,
                        MAX(date) AS last_date
                    FROM  stock_prices
                    WHERE date BETWEEN :sb_start AND :sb_end
                    GROUP BY stock_id
                ),
                first_open AS (
                    SELECT sp.stock_id, sp.open
                    FROM   stock_prices sp
                    JOIN   stock_bounds sb ON sp.stock_id = sb.stock_id
                                          AND sp.date    = sb.first_date
                ),
                last_close AS (
                    SELECT sp.stock_id, sp.close
                    FROM   stock_prices sp
                    JOIN   stock_bounds sb ON sp.stock_id = sb.stock_id
                                          AND sp.date    = sb.last_date
                ),
                prev_close AS (
                    SELECT DISTINCT ON (stock_id) stock_id, close AS prev_close
                    FROM   stock_prices
                    WHERE  date < :prev_before
                    ORDER  BY stock_id, date DESC
                ),
                agg AS (
                    SELECT
                        stock_id,
                        MAX(high)                                                      AS high,
                        MIN(low)                                                       AS low,
                        SUM(volume)                                                    AS volume,
                        ROUND(SUM(volume * (high + low + close) / 3.0)::numeric, 2)   AS traded_value,
                        COUNT(DISTINCT date)::int                                      AS trading_days
                    FROM  stock_prices
                    WHERE date BETWEEN :agg_start AND :agg_end
                    GROUP BY stock_id
                ),
                vol_ranked AS (
                    SELECT stock_id, volume,
                           ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY date DESC) AS rn
                    FROM   stock_prices
                    WHERE  date <= :ma_end
                ),
                vol_ma3 AS (
                    SELECT stock_id, ROUND(AVG(volume)::numeric, 0) AS volume_ma_3d
                    FROM   vol_ranked WHERE rn <= 3
                    GROUP BY stock_id
                ),
                vol_ma20 AS (
                    SELECT stock_id, ROUND(AVG(volume)::numeric, 0) AS volume_ma_20d
                    FROM   vol_ranked WHERE rn <= 20
                    GROUP BY stock_id
                )
                SELECT
                    s.id          AS stock_id,
                    s.symbol,
                    s.description AS company_name,
                    sec.id        AS sector_id,
                    sec.name      AS sector_name,
                    s.is_shariah,
                    fo.open,
                    agg.high,
                    agg.low,
                    lc.close,
                    agg.volume,
                    agg.traded_value,
                    ROUND((lc.close - pc.prev_close)::numeric, 2)
                        AS change_amount,
                    ROUND(((lc.close - pc.prev_close) / NULLIF(pc.prev_close, 0) * 100)::numeric, 2)
                        AS change_percent,
                    COALESCE(vm3.volume_ma_3d, 0)::bigint AS volume_ma_3d,
                    COALESCE(vm20.volume_ma_20d, 0)::bigint AS volume_ma_20d,
                    agg.trading_days
                FROM  stocks s
                JOIN  agg          ON agg.stock_id  = s.id
                JOIN  first_open fo ON fo.stock_id   = s.id
                JOIN  last_close lc ON lc.stock_id   = s.id
                LEFT  JOIN prev_close  pc   ON pc.stock_id   = s.id
                LEFT  JOIN sectors     sec  ON sec.id         = s.sector_id
                LEFT  JOIN vol_ma3     vm3  ON vm3.stock_id   = s.id
                LEFT  JOIN vol_ma20    vm20 ON vm20.stock_id  = s.id
                WHERE s.is_active = true
                  AND s.market_cap > 0
                  {$shariahFilter}
                  {$mansoorFilter}
                ORDER BY s.symbol ASC
            ", [
                'sb_start'    => $resolvedStart,
                'sb_end'      => $resolvedEnd,
                'prev_before' => $resolvedStart,
                'agg_start'   => $resolvedStart,
                'agg_end'     => $resolvedEnd,
                'ma_end'      => $resolvedEnd,
            ]));

            return $this->successResponse([
                'date'                  => $resolvedEnd,
                'start_date'            => $resolvedStart,
                'end_date'              => $resolvedEnd,
                'trading_days_in_range' => $tradingDaysInRange,
                'total_results'         => $rows->count(),
                'data'                  => $rows->values(),
            ], 'Stock prices retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock prices', $e);
        }
    }

    /**
     * Get insider trading analysis data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInsiderAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'min_free_float_pct' => 'sometimes|numeric|min:0|max:100',
                'max_free_float_pct' => 'sometimes|numeric|min:0|max:100',
                'min_change_threshold' => 'sometimes|numeric|min:0',
                'is_shariah' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $minFreeFloat = $request->get('min_free_float_pct', 15.0);
            $maxFreeFloat = $request->get('max_free_float_pct', 50.0);
            $minChangeThreshold = $request->get('min_change_threshold', 10.0);
            $isShariah = $request->has('is_shariah') ? $request->boolean('is_shariah') : null;

            // Get insider trading data with cumulative changes
            $insiderData = DB::select("
                WITH weekly_changes AS (
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

                        MIN(CASE WHEN sca.change_type = 'free_float' THEN sca.old_value END) as week_start_free_float,
                        MAX(CASE WHEN sca.change_type = 'free_float' THEN sca.new_value END) as week_end_free_float,

                        MIN(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN sca.old_value END) as week_start_total_shares,
                        MAX(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN sca.new_value END) as week_end_total_shares,

                        COUNT(CASE WHEN sca.change_type = 'free_float' THEN 1 END) as ff_changes_count,
                        COUNT(CASE WHEN sca.change_type = 'total_shares_outstanding' THEN 1 END) as shares_changes_count

                    FROM stock_share_changes_audit sca
                    INNER JOIN stocks s ON sca.stock_id = s.id
                    INNER JOIN sectors sec ON s.sector_id = sec.id
                    WHERE sca.change_date BETWEEN ? AND ?
                      AND s.is_active = true
                      AND s.market_cap > 0
                      " . ($isShariah !== null ? "AND s.is_shariah = ?" : "") . "
                      AND s.total_shares_outstanding > 0
                      AND s.free_float > 0
                      AND ((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100) BETWEEN ? AND ?
                      AND sca.change_type != 'stock_split'
                      AND sca.change_type IN ('free_float', 'total_shares_outstanding')
                    GROUP BY sca.symbol, sca.stock_id, s.description, sec.name, s.total_shares_outstanding, s.free_float
                ),
                calculated_changes AS (
                    SELECT
                        wc.*,
                        CASE
                            WHEN wc.week_start_free_float > 0 AND wc.week_end_free_float IS NOT NULL THEN
                                ROUND(((wc.week_end_free_float - wc.week_start_free_float) / wc.week_start_free_float::numeric * 100), 2)
                            ELSE NULL
                        END as cumulative_ff_change_pct,

                        CASE
                            WHEN wc.week_start_total_shares > 0 AND wc.week_end_total_shares IS NOT NULL THEN
                                ROUND(((wc.week_end_total_shares - wc.week_start_total_shares) / wc.week_start_total_shares::numeric * 100), 2)
                            ELSE NULL
                        END as cumulative_shares_change_pct
                    FROM weekly_changes wc
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
            ", array_filter([
                $startDate, $endDate,
                $isShariah !== null ? $isShariah : null,
                $minFreeFloat, $maxFreeFloat, $minChangeThreshold, $minChangeThreshold
            ], function ($value) {
                return $value !== null;
            }));

            // Categorize the results
            $categorized = [
                'insider_buying' => [],
                'insider_selling' => [],
                'share_buybacks' => [],
                'share_issuances' => []
            ];

            foreach ($insiderData as $item) {
                if ($item->cumulative_ff_change_pct !== null) {
                    if ($item->cumulative_ff_change_pct <= -$minChangeThreshold) {
                        $categorized['insider_buying'][] = $item;
                    } elseif ($item->cumulative_ff_change_pct >= $minChangeThreshold) {
                        $categorized['insider_selling'][] = $item;
                    }
                }

                if ($item->cumulative_shares_change_pct !== null) {
                    if ($item->cumulative_shares_change_pct <= -$minChangeThreshold) {
                        $categorized['share_buybacks'][] = $item;
                    } elseif ($item->cumulative_shares_change_pct >= $minChangeThreshold) {
                        $categorized['share_issuances'][] = $item;
                    }
                }
            }

            return $this->successResponse([
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'filters' => [
                    'min_free_float_pct' => $minFreeFloat,
                    'max_free_float_pct' => $maxFreeFloat,
                    'min_change_threshold' => $minChangeThreshold,
                    'is_shariah' => $isShariah
                ],
                'summary' => [
                    'total_changes' => count($insiderData),
                    'insider_buying_count' => count($categorized['insider_buying']),
                    'insider_selling_count' => count($categorized['insider_selling']),
                    'share_buybacks_count' => count($categorized['share_buybacks']),
                    'share_issuances_count' => count($categorized['share_issuances'])
                ],
                'categorized_data' => $categorized,
                'all_changes' => $insiderData
            ], 'Insider analysis data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving insider analysis data', $e);
        }
    }

    public function getLastTradingDate(Request $request): JsonResponse
    {
        try {
            $lastDate = DB::table('stock_prices')->max('date');

            if (!$lastDate) {
                return $this->successResponse([
                    'last_trading_date' => null
                ], 'No trading data available');
            }

            return $this->successResponse([
                'last_trading_date' => $lastDate
            ], 'Last trading date retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving last trading date', $e);
        }
    }
}
