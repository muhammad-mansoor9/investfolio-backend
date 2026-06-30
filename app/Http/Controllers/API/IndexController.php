<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Index;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IndexController extends Controller
{
    /**
     * Get all indices with optional filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getIndices(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'with_latest_price' => 'sometimes|boolean',
                'with_stocks' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $query = Index::query();

            // Apply filters
            if ($request->has('exchange_id')) {
                $query->where('exchange_id', $request->get('exchange_id'));
            }

            // Eager load relationships
            if ($request->boolean('with_latest_price', true)) {
                $query->with('latestPrice');
            }

            if ($request->boolean('with_stocks', false)) {
                $query->with(['stocks' => function ($query) {
                    $query->orderBy('index_stocks.weightage', 'desc');
                }]);
            }

            $indices = $query->orderBy('symbol')->get();

            $data = $indices->map(function ($index) use ($request) {
                $result = [
                    'id' => $index->id,
                    'symbol' => $index->symbol,
                    'description' => $index->description,
                    'total_stocks' => $index->total_stocks,
                ];

                if ($request->boolean('with_latest_price', true) && $index->latestPrice) {
                    $result['latest_price'] = [
                        'open' => $index->latestPrice->open,
                        'high' => $index->latestPrice->high,
                        'low' => $index->latestPrice->low,
                        'close' => $index->latestPrice->close,
                        'price' => $index->latestPrice->price,
                        'change' => $index->latestPrice->change,
                        'date' => $index->latestPrice->date,
                    ];
                }

                if ($request->boolean('with_stocks', false) && $index->relationLoaded('stocks')) {
                    $result['stocks_count'] = $index->stocks->count();
                }

                return $result;
            });

            return $this->successResponse([
                'indices' => $data,
                'total_count' => $data->count(),
            ], 'Indices retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving indices', $e);
        }
    }

    /**
     * Get stocks in a specific index
     *
     * @param Request $request
     * @param string $indexId
     * @return JsonResponse
     */
    public function getIndexStocks(Request $request, string $indexId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'with_latest_price' => 'sometimes|boolean',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $index = Index::find($indexId);

            if (!$index) {
                return $this->notFoundResponse('Index not found');
            }

            $stocksQuery = $index->stocks()
                ->with([
                    'sector:id,name',
                ]);

            // Add latest price if requested
            if ($request->boolean('with_latest_price', true)) {
                $stocksQuery->with('latestPrice');
            }

            // Order by weightage descending
            $stocksQuery->orderBy('index_stocks.weightage', 'desc');

            // Apply limit if specified
            if ($request->has('limit')) {
                $stocksQuery->limit($request->get('limit'));
            }

            $stocks = $stocksQuery->get();

            $data = [
                'index' => [
                    'id' => $index->id,
                    'symbol' => $index->symbol,
                    'description' => $index->description,
                    'total_stocks' => $index->total_stocks,
                ],
                'stocks' => $stocks->map(function ($stock) use ($request) {
                    $result = [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'description' => $stock->description,
                        'is_shariah' => $stock->is_shariah,
                        'sector' => $stock->sector ? [
                            'id' => $stock->sector->id,
                            'name' => $stock->sector->name,
                        ] : null,
                        'index_weightage' => $stock->pivot->weightage,
                    ];

                    if ($request->boolean('with_latest_price', true) && $stock->latestPrice) {
                        $result['latest_price'] = [
                            'price' => $stock->latestPrice->price,
                            'close' => $stock->latestPrice->close,
                            'change' => $stock->latestPrice->change,
                            'change_percent' => $stock->latestPrice->change_percent,
                            'volume' => $stock->latestPrice->volume,
                            'date' => $stock->latestPrice->date,
                        ];
                    }

                    return $result;
                }),
                'total_stocks_count' => $stocks->count(),
            ];

            return $this->successResponse($data, 'Index stocks retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving index stocks', $e);
        }
    }

    /**
     * Get stocks in an index by symbol
     *
     * @param Request $request
     * @param string $symbol
     * @return JsonResponse
     */
    public function getIndexStocksBySymbol(Request $request, string $symbol): JsonResponse
    {
        try {
            $index = Index::where('symbol', strtoupper($symbol))
                ->first();

            if (!$index) {
                return $this->notFoundResponse("Index with symbol '{$symbol}' not found");
            }

            return $this->getIndexStocks($request, $index->id);

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving index stocks', $e);
        }
    }

    /**
     * Get index price history
     *
     * @param Request $request
     * @param string $indexId
     * @return JsonResponse
     */
    public function getIndexPriceHistory(Request $request, string $indexId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date|after_or_equal:start_date',
                'limit' => 'sometimes|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $index = Index::find($indexId);

            if (!$index) {
                return $this->notFoundResponse('Index not found');
            }

            $pricesQuery = $index->prices()->orderBy('date', 'desc');

            if ($request->has('start_date')) {
                $pricesQuery->where('date', '>=', $request->get('start_date'));
            }

            if ($request->has('end_date')) {
                $pricesQuery->where('date', '<=', $request->get('end_date'));
            }

            if ($request->has('limit')) {
                $pricesQuery->limit($request->get('limit'));
            } else {
                $pricesQuery->limit(30); // Default to last 30 days
            }

            $prices = $pricesQuery->get();

            $data = [
                'index' => [
                    'id' => $index->id,
                    'symbol' => $index->symbol,
                    'description' => $index->description,
                ],
                'price_history' => $prices->map(function ($price) {
                    return [
                        'open' => $price->open,
                        'high' => $price->high,
                        'low' => $price->low,
                        'close' => $price->close,
                        'price' => $price->price,
                        'change' => $price->change,
                        'date' => $price->date,
                    ];
                }),
                'total_records' => $prices->count(),
            ];

            return $this->successResponse($data, 'Index price history retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving price history', $e);
        }
    }
}
