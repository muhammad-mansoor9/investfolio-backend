<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VolumeAnalysisController extends Controller
{
    /**
     * Get volume analysis for stocks or sectors
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:stocks,sectors',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'min_float' => 'nullable|numeric|min:0',
                'max_float' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->get('type');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $minFloat = $request->get('min_float', 10); // Default 10
            $maxFloat = $request->get('max_float', 100); // Default 100

            // Validate min < max
            if ($minFloat >= $maxFloat) {
                return $this->validationErrorResponse(['min_float' => 'Min float must be less than max float']);
            }

            if ($type === 'stocks') {
                return $this->getStockVolumeSummary($startDate, $endDate, $minFloat, $maxFloat);
            } else {
                return $this->getSectorVolumeSummary($startDate, $endDate, $minFloat, $maxFloat);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving volume summary', $e);
        }
    }

    /**
     * Get volume summary for stocks
     */
    private function getStockVolumeSummary($startDate, $endDate, $minFloat, $maxFloat): JsonResponse
    {
        // First get stocks in the free_float range
        $validStockIds = DB::table('stocks')
            ->select('id')
            ->where('is_active', true)
            ->where('market_cap', '>', 0)
            ->whereNotNull('free_float')
            ->whereNotNull('total_shares_outstanding')
            ->where('total_shares_outstanding', '>', 0)
            ->whereRaw('(CAST(free_float AS DECIMAL(20,2)) / CAST(total_shares_outstanding AS DECIMAL(20,2))) * 100 BETWEEN ? AND ?', [$minFloat, $maxFloat])
            ->pluck('id');

        // Get aggregated volume data per stock with free_float % filter
        $stocksData = DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
            ->leftJoin('exchange_markets as em', 's.exchange_id', '=', 'em.id')
            ->select([
                's.id as stock_id',
                's.symbol',
                's.description',
                's.is_shariah',
                's.market_cap',
                's.free_float',
                's.total_shares_outstanding',
                DB::raw('CASE WHEN s.total_shares_outstanding > 0 THEN (CAST(s.free_float AS DECIMAL(20,2)) / CAST(s.total_shares_outstanding AS DECIMAL(20,2))) * 100 ELSE 0 END as free_float_percent'),
                'sec.id as sector_id',
                'sec.name as sector_name',
                'em.id as exchange_id',
                'em.name as exchange_name',
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('COUNT(*) as trading_days'),
            ])
            ->whereIn('s.id', $validStockIds)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->groupBy([
                's.id', 's.symbol', 's.description', 's.is_shariah', 's.market_cap', 's.free_float', 's.total_shares_outstanding',
                'sec.id', 'sec.name', 'em.id', 'em.name'
            ])
            ->orderBy('total_volume', 'desc')
            ->get();

        // Get the most recent date with data (not necessarily end date)
        $mostRecentDate = DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->limit(1)
            ->value('date');

        // Get today's volume (most recent date with actual data)
        $todayVolumes = $mostRecentDate ? DB::table('stock_prices')
            ->select('stock_id', 'volume', 'price', 'close', 'open')
            ->where('date', $mostRecentDate)
            ->get()
            ->keyBy('stock_id') : collect();

        // Get yesterday's volume (second most recent date)
        $yesterdayDate = $mostRecentDate ? DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->where('date', '<', $mostRecentDate)
            ->orderBy('date', 'desc')
            ->limit(1)
            ->value('date') : null;

        $yesterdayVolumes = $yesterdayDate ? DB::table('stock_prices')
            ->select('stock_id', 'volume')
            ->where('date', $yesterdayDate)
            ->get()
            ->keyBy('stock_id') : collect();

        // Get the first available date in range (opening prices)
        $firstAvailableDate = DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->limit(1)
            ->value('date');

        // Get opening prices (use 'price' from first available date in range)
        $openingPrices = $firstAvailableDate ? DB::table('stock_prices')
            ->select('stock_id', 'price as opening_price')
            ->where('date', $firstAvailableDate)
            ->get()
            ->keyBy('stock_id') : collect();

        // Calculate moving averages efficiently using window functions
        $movingAverages = DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->select([
                'sp.stock_id',
                DB::raw('AVG(sp.volume) OVER (
                    PARTITION BY sp.stock_id
                    ORDER BY sp.date DESC
                    ROWS BETWEEN CURRENT ROW AND 2 FOLLOWING
                ) as avg_3d'),
                DB::raw('AVG(sp.volume) OVER (
                    PARTITION BY sp.stock_id
                    ORDER BY sp.date DESC
                    ROWS BETWEEN CURRENT ROW AND 19 FOLLOWING
                ) as avg_20d'),
                DB::raw('ROW_NUMBER() OVER (
                    PARTITION BY sp.stock_id
                    ORDER BY sp.date DESC
                ) as rn')
            ])
            ->where('s.is_active', true)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->get()
            ->filter(function($item) {
                return $item->rn == 1;
            })
            ->keyBy('stock_id');

        // Format data with new column structure
        $data = $stocksData->map(function ($item) use ($movingAverages, $todayVolumes, $yesterdayVolumes, $openingPrices) {
            $avgVolume = $item->trading_days > 0 ? $item->total_volume / $item->trading_days : 0;
            $ma = $movingAverages->get($item->stock_id);
            $today = $todayVolumes->get($item->stock_id);
            $yesterday = $yesterdayVolumes->get($item->stock_id);
            $opening = $openingPrices->get($item->stock_id);

            // Calculate percentages
            $todayVs3d = null;
            $threeVs20d = null;
            if ($ma && $today) {
                $todayVs3d = $ma->avg_3d > 0 ? (($today->volume - $ma->avg_3d) / $ma->avg_3d) * 100 : null;
                $threeVs20d = $ma->avg_20d > 0 ? (($ma->avg_3d - $ma->avg_20d) / $ma->avg_20d) * 100 : null;
            }

            // Calculate price change (price from most recent date - price from first date)
            $priceChange = null;
            $priceChangePercent = null;
            if ($opening && $today && isset($today->price) && isset($opening->opening_price)) {
                $priceChange = $today->price - $opening->opening_price;
                $priceChangePercent = $opening->opening_price > 0 ? ($priceChange / $opening->opening_price) * 100 : null;
            }

            return [
                'stock_id' => $item->stock_id,
                'symbol' => $item->symbol,
                'description' => $item->description,
                'is_shariah' => $item->is_shariah,
                'market_cap' => $item->market_cap,
                'sector' => $item->sector_id ? [
                    'id' => $item->sector_id,
                    'name' => $item->sector_name
                ] : null,
                'exchange' => $item->exchange_id ? [
                    'id' => $item->exchange_id,
                    'name' => $item->exchange_name
                ] : null,
                'today_volume' => $today ? (float) $today->volume : null,
                'yesterday_volume' => $yesterday ? (float) $yesterday->volume : null,
                'avg_volume' => (float) $avgVolume,
                'today_vs_3d_percent' => $todayVs3d,
                'three_vs_20d_percent' => $threeVs20d,
                'price_change' => $priceChange,
                'price_change_percent' => $priceChangePercent,
                'trading_days' => (int) $item->trading_days,
            ];
        });

        // Summary
        $summary = [
            'total_stocks' => $data->count(),
            'total_volume' => $data->sum('today_volume'),
            'avg_volume_per_stock' => $data->avg('today_volume'),
            'highest_volume_stock' => $data->first() ? [
                'symbol' => $data->first()['symbol'],
                'volume' => $data->first()['today_volume']
            ] : null,
        ];

        return $this->successResponse([
            'type' => 'stocks',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'data' => $data,
        ], 'Stock volume summary retrieved successfully');
    }

    /**
     * Get volume summary for sectors
     */
    private function getSectorVolumeSummary($startDate, $endDate, $minFloat, $maxFloat): JsonResponse
    {
        // First get stocks in the free_float range
        $validStockIds = DB::table('stocks')
            ->select('id')
            ->where('is_active', true)
            ->where('market_cap', '>', 0)
            ->whereNotNull('free_float')
            ->whereNotNull('total_shares_outstanding')
            ->where('total_shares_outstanding', '>', 0)
            ->whereRaw('(CAST(free_float AS DECIMAL(20,2)) / CAST(total_shares_outstanding AS DECIMAL(20,2))) * 100 BETWEEN ? AND ?', [$minFloat, $maxFloat])
            ->pluck('id');

        // Get aggregated volume data per sector with free_float % filter
        $sectorsData = DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->join('sectors as sec', 's.sector_id', '=', 'sec.id')
            ->select([
                'sec.id as sector_id',
                'sec.name as sector_name',
                DB::raw('COUNT(DISTINCT s.id) as total_stocks'),
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('COUNT(DISTINCT sp.date) as trading_days'),
            ])
            ->whereIn('s.id', $validStockIds)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->groupBy('sec.id', 'sec.name')
            ->orderBy('total_volume', 'desc')
            ->get();

        // Get the most recent date with data
        $mostRecentDate = DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->limit(1)
            ->value('date');

        // Get today's volume per sector (most recent date with actual data)
        $todaySectorVolumes = $mostRecentDate ? DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->select([
                's.sector_id',
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('AVG(sp.price) as avg_price'),
                DB::raw('AVG(sp.close) as avg_close')
            ])
            ->where('sp.date', $mostRecentDate)
            ->whereIn('s.id', $validStockIds)
            ->groupBy('s.sector_id')
            ->get()
            ->keyBy('sector_id') : collect();

        // Get yesterday's volume per sector
        $yesterdayDate = $mostRecentDate ? DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->where('date', '<', $mostRecentDate)
            ->orderBy('date', 'desc')
            ->limit(1)
            ->value('date') : null;

        $yesterdaySectorVolumes = $yesterdayDate ? DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->select([
                's.sector_id',
                DB::raw('SUM(sp.volume) as total_volume')
            ])
            ->where('sp.date', $yesterdayDate)
            ->whereIn('s.id', $validStockIds)
            ->groupBy('s.sector_id')
            ->get()
            ->keyBy('sector_id') : collect();

        // Get the first available date in range
        $firstAvailableDate = DB::table('stock_prices')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->limit(1)
            ->value('date');

        // Get opening prices per sector (first available date in range)
        $openingSectorPrices = $firstAvailableDate ? DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->select([
                's.sector_id',
                DB::raw('AVG(sp.price) as avg_opening_price')
            ])
            ->where('sp.date', $firstAvailableDate)
            ->whereIn('s.id', $validStockIds)
            ->groupBy('s.sector_id')
            ->get()
            ->keyBy('sector_id') : collect();

        // Calculate moving averages efficiently using window functions for sectors
        // Convert to array for binding
        $validStockIdsArray = $validStockIds->toArray();
        $placeholders = implode(',', array_fill(0, count($validStockIdsArray), '?'));

        $movingAverages = DB::table(DB::raw("(
            SELECT
                s.sector_id,
                sp.date,
                SUM(sp.volume) as daily_volume,
                AVG(SUM(sp.volume)) OVER (
                    PARTITION BY s.sector_id
                    ORDER BY sp.date DESC
                    ROWS BETWEEN CURRENT ROW AND 2 FOLLOWING
                ) as avg_3d,
                AVG(SUM(sp.volume)) OVER (
                    PARTITION BY s.sector_id
                    ORDER BY sp.date DESC
                    ROWS BETWEEN CURRENT ROW AND 19 FOLLOWING
                ) as avg_20d,
                ROW_NUMBER() OVER (
                    PARTITION BY s.sector_id
                    ORDER BY sp.date DESC
                ) as rn
            FROM stock_prices sp
            JOIN stocks s ON sp.stock_id = s.id
            WHERE s.id IN ($placeholders)
            AND sp.date BETWEEN ? AND ?
            GROUP BY s.sector_id, sp.date
        ) as daily_data"))
            ->setBindings(array_merge($validStockIdsArray, [$startDate, $endDate]))
            ->whereRaw('rn = 1')
            ->get()
            ->keyBy('sector_id');

        // Format data with new column structure
        $data = $sectorsData->map(function ($item) use ($movingAverages, $todaySectorVolumes, $yesterdaySectorVolumes, $openingSectorPrices) {
            $avgVolume = $item->trading_days > 0 ? $item->total_volume / $item->trading_days : 0;
            $ma = $movingAverages->get($item->sector_id);
            $today = $todaySectorVolumes->get($item->sector_id);
            $yesterday = $yesterdaySectorVolumes->get($item->sector_id);
            $opening = $openingSectorPrices->get($item->sector_id);

            // Calculate percentages
            $todayVs3d = null;
            $threeVs20d = null;
            if ($ma && $today) {
                $todayVs3d = $ma->avg_3d > 0 ? (($today->total_volume - $ma->avg_3d) / $ma->avg_3d) * 100 : null;
                $threeVs20d = $ma->avg_20d > 0 ? (($ma->avg_3d - $ma->avg_20d) / $ma->avg_20d) * 100 : null;
            }

            // Calculate price change (avg price from most recent - avg price from first)
            $priceChange = null;
            $priceChangePercent = null;
            if ($opening && $today && isset($today->avg_price) && isset($opening->avg_opening_price)) {
                $priceChange = $today->avg_price - $opening->avg_opening_price;
                $priceChangePercent = $opening->avg_opening_price > 0 ? ($priceChange / $opening->avg_opening_price) * 100 : null;
            }

            return [
                'sector_id' => $item->sector_id,
                'sector_name' => $item->sector_name,
                'total_stocks' => (int) $item->total_stocks,
                'today_volume' => $today ? (float) $today->total_volume : null,
                'yesterday_volume' => $yesterday ? (float) $yesterday->total_volume : null,
                'avg_volume' => (float) $avgVolume,
                'today_vs_3d_percent' => $todayVs3d,
                'three_vs_20d_percent' => $threeVs20d,
                'price_change' => $priceChange,
                'price_change_percent' => $priceChangePercent,
                'trading_days' => (int) $item->trading_days,
            ];
        });

        // Summary
        $summary = [
            'total_sectors' => $data->count(),
            'total_volume' => $data->sum('today_volume'),
            'avg_volume_per_sector' => $data->avg('today_volume'),
            'highest_volume_sector' => $data->first() ? [
                'name' => $data->first()['sector_name'],
                'volume' => $data->first()['today_volume']
            ] : null,
            'total_stocks_analyzed' => $data->sum('total_stocks'),
        ];

        return $this->successResponse([
            'type' => 'sectors',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'data' => $data,
        ], 'Sector volume summary retrieved successfully');
    }
}
