<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Helpers\CacheHelper;
use App\Services\UINSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UinSettlementController extends Controller
{
    public function __construct(
        private UINSettlementService $uinService,
    ) {}
    /**
     * Get UIN settlement analysis data with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUinSettlementAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|uuid|exists:stocks,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $stockId = $request->get('stock_id');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

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

            $allSettlementData = $this->uinService->getSettlementData();

            $settlementData = CacheHelper::normalize($allSettlementData)
                ->filter(fn($item) => $item['stock_id'] === $stockId &&
                                     $item['settlement_date'] >= $startDate &&
                                     $item['settlement_date'] <= $endDate)
                ->sortBy('settlement_date')
                ->values();

            if ($settlementData->isEmpty()) {
                $settlementData = DB::table('uin_settlement_data')
                    ->select([
                        DB::raw("TO_CHAR(settlement_date, 'YYYY-MM-DD') as settlement_date"),
                        'trade_volume',
                        'trade_value',
                        'uin_settlement_volume',
                        'uin_settlement_value',
                        'uin_percentage_volume',
                        'uin_percentage_value'
                    ])
                    ->where('stock_id', $stockId)
                    ->whereBetween('settlement_date', [$startDate, $endDate])
                    ->orderBy('settlement_date', 'asc')
                    ->get()
                    ->map(fn($item) => (array) $item);
            }

            // Calculate summary statistics
            $summary = [
                'total_records' => $settlementData->count(),
                'avg_uin_percentage_volume' => $settlementData->avg('uin_percentage_volume'),
                'avg_uin_percentage_value' => $settlementData->avg('uin_percentage_value'),
                'max_uin_percentage_volume' => $settlementData->max('uin_percentage_volume'),
                'max_uin_percentage_value' => $settlementData->max('uin_percentage_value'),
                'min_uin_percentage_volume' => $settlementData->min('uin_percentage_volume'),
                'min_uin_percentage_value' => $settlementData->min('uin_percentage_value'),
                'total_trade_volume' => $settlementData->sum('trade_volume'),
                'total_trade_value' => $settlementData->sum('trade_value'),
                'total_uin_settlement_volume' => $settlementData->sum('uin_settlement_volume'),
                'total_uin_settlement_value' => $settlementData->sum('uin_settlement_value'),
            ];

            // Format the data
            $data = $settlementData->map(function ($item) {
                return [
                    'date' => $item->settlement_date, // Already formatted as YYYY-MM-DD string
                    'trade_volume' => (float) $item->trade_volume,
                    'trade_value' => (float) $item->trade_value,
                    'uin_settlement_volume' => (float) $item->uin_settlement_volume,
                    'uin_settlement_value' => (float) $item->uin_settlement_value,
                    'uin_percentage_volume' => (float) $item->uin_percentage_volume,
                    'uin_percentage_value' => (float) $item->uin_percentage_value,
                ];
            });

            return $this->successResponse([
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
            ], 'UIN settlement analysis data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving UIN settlement analysis', $e);
        }
    }

    /**
     * Get list of stocks for dropdown (active stocks with UIN settlement data)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStocksWithUinData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'sometimes|string|max:255',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $searchQuery = $request->get('search', '');
            $limit = $request->get('limit', 100);

            // Get stocks that have UIN settlement data
            $query = DB::table('stocks as s')
                ->join(DB::raw('(SELECT DISTINCT stock_id FROM uin_settlement_data) as usd'), 's.id', '=', 'usd.stock_id')
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
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0);

            // Apply search filter if provided
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

            // Format response
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
                    'exchange' => $stock->exchange_id ? [
                        'id' => $stock->exchange_id,
                        'name' => $stock->exchange_name
                    ] : null,
                ];
            });

            return $this->successResponse([
                'stocks' => $formattedStocks,
                'total_results' => $formattedStocks->count()
            ], 'Stocks with UIN data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stocks', $e);
        }
    }

    /**
     * Get UIN settlement data statistics for multiple stocks (comparison)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUinSettlementComparison(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_ids' => 'required|array|min:1|max:10',
                'stock_ids.*' => 'required|uuid|exists:stocks,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $stockIds = $request->get('stock_ids');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $comparisonData = [];

            $allSettlementData = $this->uinService->getSettlementData();
            $normalizedData = CacheHelper::normalize($allSettlementData);

            foreach ($stockIds as $stockId) {
                $stock = DB::table('stocks')->where('id', $stockId)->first();

                if (!$stock) {
                    continue;
                }

                $stockData = $normalizedData
                    ->filter(fn($item) => $item['stock_id'] === $stockId &&
                                         $item['settlement_date'] >= $startDate &&
                                         $item['settlement_date'] <= $endDate);

                if ($stockData->isEmpty()) {
                    $stockData = DB::table('uin_settlement_data')
                        ->where('stock_id', $stockId)
                        ->whereBetween('settlement_date', [$startDate, $endDate])
                        ->get()
                        ->map(fn($item) => (array) $item);
                }

                $avgUinVolume = $stockData->avg('uin_percentage_volume') ?? 0;
                $avgUinValue = $stockData->avg('uin_percentage_value') ?? 0;

                $comparisonData[] = [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'avg_uin_percentage_volume' => (float) $avgUinVolume,
                    'avg_uin_percentage_value' => (float) $avgUinValue,
                    'total_records' => $stockData->count()
                ];
            }

            return $this->successResponse([
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'comparison_data' => $comparisonData
            ], 'UIN settlement comparison data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving comparison data', $e);
        }
    }
}
