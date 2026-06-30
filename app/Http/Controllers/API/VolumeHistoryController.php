<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VolumeHistoryController extends Controller
{
    /**
     * Get volume analysis data with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'nullable|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $stockId = $request->get('stock_id');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            // Validate stock exists if not "all"
            if ($stockId && $stockId !== 'all') {
                $stockExists = DB::table('stocks')->where('id', $stockId)->exists();
                if (!$stockExists) {
                    return $this->validationErrorResponse(['stock_id' => ['The selected stock does not exist.']]);
                }
                // Individual stock analysis
                return $this->getIndividualStockAnalysis($stockId, $startDate, $endDate);
            } else {
                // All stocks analysis (aggregated)
                return $this->getAllStocksAnalysis($startDate, $endDate);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving volume analysis', $e);
        }
    }

    /**
     * Get analysis for individual stock (one entry per date)
     */
    private function getIndividualStockAnalysis($stockId, $startDate, $endDate): JsonResponse
    {
        // Get stock details
        $stock = DB::table('stocks as s')
            ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
            ->leftJoin('exchange_markets as em', 's.exchange_id', '=', 'em.id')
            ->select([
                's.id',
                's.symbol',
                's.description',
                's.is_shariah',
                's.market_cap',
                'sec.id as sector_id',
                'sec.name as sector_name',
                'em.id as exchange_id',
                'em.name as exchange_name'
            ])
            ->where('s.id', $stockId)
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0)
            ->first();

        if (!$stock) {
            return $this->notFoundResponse('Stock not found');
        }

        // Get price data for date range (one entry per date)
        $priceData = DB::table('stock_prices')
            ->select([
                DB::raw("TO_CHAR(date, 'YYYY-MM-DD') as date"),
                'open',
                'high',
                'low',
                'close',
                'price',
                'volume',
                'change'
            ])
            ->where('stock_id', $stockId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get();

        // Calculate moving averages based on trading days (available data points)
        $dataWithAverages = $priceData->map(function ($item, $index) use ($priceData) {
            // 3-day average volume (last 3 trading days including current)
            $startIndex3 = max(0, $index - 2);
            $count3 = $index + 1 - $startIndex3; // Actual number of trading days
            $slice3 = $priceData->slice($startIndex3, $count3);
            $avg3 = $slice3->avg('volume');

            // 20-day average volume (last 20 trading days including current)
            $startIndex20 = max(0, $index - 19);
            $count20 = $index + 1 - $startIndex20; // Actual number of trading days
            $slice20 = $priceData->slice($startIndex20, $count20);
            $avg20 = $slice20->avg('volume');

            return [
                'date' => $item->date,
                'open' => $item->open,
                'high' => $item->high,
                'low' => $item->low,
                'close' => $item->close,
                'price' => $item->price,
                'volume' => $item->volume,
                'volume_avg_3d' => $avg3,
                'volume_avg_20d' => $avg20,
                'change' => $item->change,
            ];
        });

        // Calculate summary
        $summary = [
            'total_records' => $dataWithAverages->count(),
            'total_volume' => $dataWithAverages->sum('volume'),
            'avg_volume' => $dataWithAverages->avg('volume'),
            'max_volume' => $dataWithAverages->max('volume'),
            'min_volume' => $dataWithAverages->min('volume'),
            'avg_price' => $dataWithAverages->avg('close'),
            'highest_price' => $dataWithAverages->max('high'),
            'lowest_price' => $dataWithAverages->min('low'),
            'opening_price' => $dataWithAverages->first()['open'] ?? null,
            'closing_price' => $dataWithAverages->last()['close'] ?? null,
            'price_change' => $dataWithAverages->first() && $dataWithAverages->last()
                ? (float)$dataWithAverages->last()['close'] - (float)$dataWithAverages->first()['open']
                : 0,
            'price_change_percent' => $dataWithAverages->first() && $dataWithAverages->first()['open'] > 0
                ? (((float)$dataWithAverages->last()['close'] - (float)$dataWithAverages->first()['open']) / (float)$dataWithAverages->first()['open']) * 100
                : 0,
        ];

        // Format data for response
        $data = $dataWithAverages->map(function ($item) {
            return [
                'date' => $item['date'],
                'open' => (float) $item['open'],
                'high' => (float) $item['high'],
                'low' => (float) $item['low'],
                'close' => (float) $item['close'],
                'price' => (float) $item['price'],
                'volume' => (float) $item['volume'],
                'volume_avg_3d' => (float) $item['volume_avg_3d'],
                'volume_avg_20d' => (float) $item['volume_avg_20d'],
                'change' => (float) $item['change'],
            ];
        });

        return $this->successResponse([
            'analysis_type' => 'individual',
            'stock' => [
                'id' => $stock->id,
                'symbol' => $stock->symbol,
                'description' => $stock->description,
                'is_shariah' => $stock->is_shariah,
                'market_cap' => $stock->market_cap,
                'sector' => $stock->sector_id ? [
                    'id' => $stock->sector_id,
                    'name' => $stock->sector_name
                ] : null,
                'exchange' => $stock->exchange_id ? [
                    'id' => $stock->exchange_id,
                    'name' => $stock->exchange_name
                ] : null,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'summary' => $summary,
            'data' => $data
        ], 'Volume analysis data retrieved successfully');
    }

    /**
     * Get analysis for all stocks (aggregated per stock for the date range)
     */
    private function getAllStocksAnalysis($startDate, $endDate): JsonResponse
    {
        // Get aggregated data per stock for the entire date range
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
                'sec.id as sector_id',
                'sec.name as sector_name',
                'em.id as exchange_id',
                'em.name as exchange_name',
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('AVG(sp.volume) as avg_volume'),
                DB::raw('MAX(sp.volume) as max_volume'),
                DB::raw('MIN(sp.volume) as min_volume'),
                DB::raw('AVG(sp.close) as avg_close'),
                DB::raw('MAX(sp.high) as highest_price'),
                DB::raw('MIN(sp.low) as lowest_price'),
                // Get first open and last close for the date range
                DB::raw("(SELECT open FROM stock_prices WHERE stock_id = s.id AND date >= '{$startDate}' ORDER BY date ASC LIMIT 1) as opening_price"),
                DB::raw("(SELECT close FROM stock_prices WHERE stock_id = s.id AND date <= '{$endDate}' ORDER BY date DESC LIMIT 1) as closing_price"),
                DB::raw('COUNT(*) as trading_days')
            ])
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->groupBy([
                's.id', 's.symbol', 's.description', 's.is_shariah', 's.market_cap',
                'sec.id', 'sec.name', 'em.id', 'em.name'
            ])
            ->orderBy('s.symbol', 'asc')
            ->get();

        // Calculate price changes and moving averages
        $data = $stocksData->map(function ($item) use ($startDate, $endDate) {
            $opening = (float) $item->opening_price;
            $closing = (float) $item->closing_price;

            $priceChange = $closing - $opening;
            $priceChangePercent = $opening > 0 ? ($priceChange / $opening) * 100 : 0;

            // Calculate 3-day and 20-day moving averages for this stock
            $volumes = DB::table('stock_prices')
                ->where('stock_id', $item->stock_id)
                ->whereBetween('date', [$startDate, $endDate])
                ->orderBy('date', 'desc')
                ->limit(20)
                ->pluck('volume')
                ->toArray();

            $volumesCount = count($volumes);
            $avg3d = $volumesCount >= 3 ? array_sum(array_slice($volumes, 0, 3)) / 3 : null;
            $avg20d = $volumesCount >= 20 ? array_sum($volumes) / 20 : null;

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
                'total_volume' => (float) $item->total_volume,
                'avg_volume' => (float) $item->avg_volume,
                'volume_avg_3d' => $avg3d,
                'volume_avg_20d' => $avg20d,
                'max_volume' => (float) $item->max_volume,
                'min_volume' => (float) $item->min_volume,
                'avg_close' => (float) $item->avg_close,
                'highest_price' => (float) $item->highest_price,
                'lowest_price' => (float) $item->lowest_price,
                'opening_price' => $opening,
                'closing_price' => $closing,
                'price_change' => $priceChange,
                'price_change_percent' => $priceChangePercent,
                'trading_days' => (int) $item->trading_days,
            ];
        });

        // Overall summary
        $summary = [
            'total_stocks' => $data->count(),
            'total_volume' => $data->sum('total_volume'),
            'avg_volume_per_stock' => $data->avg('total_volume'),
            'highest_volume_stock' => $data->first() ? [
                'symbol' => $data->first()['symbol'],
                'volume' => $data->first()['total_volume']
            ] : null,
        ];

        return $this->successResponse([
            'analysis_type' => 'all_stocks',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'summary' => $summary,
            'data' => $data
        ], 'Volume analysis data retrieved successfully');
    }
}
