<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DividendHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DividendHistoryController extends Controller
{
    /**
     * Get all dividends by stock ID
     *
     * @param string $stockId
     * @return JsonResponse
     */
    public function getDividendsByStock(string $stockId): JsonResponse
    {
        try {
            $dividends = DividendHistory::query()
                ->with([
                    'stock:id,symbol,description',
                    'stock.sector:id,name',
                ])
                ->forStock($stockId)
                ->orderByPayDate('desc')
                ->get();

            if ($dividends->isEmpty()) {
                return $this->successResponse([
                    'dividends' => [],
                    'total_count' => 0,
                ], 'No dividends found for this stock');
            }

            $data = [
                'stock' => [
                    'id' => $dividends->first()->stock->id,
                    'symbol' => $dividends->first()->stock->symbol,
                    'description' => $dividends->first()->stock->description,
                ],
                'dividends' => $dividends->map(function ($dividend) {
                    return [
                        'id' => $dividend->id,
                        'pay_date' => $dividend->pay_date ? $dividend->pay_date->format('Y-m-d') : null,
                        'ex_date' => $dividend->ex_date ? $dividend->ex_date->format('Y-m-d') : null,
                        'type' => $dividend->type,
                        'amount' => $dividend->amount,
                        'amount_split_adj' => $dividend->amount_split_adj,
                        'created_at' => $dividend->created_at,
                        'updated_at' => $dividend->updated_at,
                    ];
                }),
                'total_count' => $dividends->count(),
                'total_amount' => $dividends->sum('amount'),
            ];

            return $this->successResponse($data, 'Dividends retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving dividends', $e);
        }
    }

    /**
     * Get dividends with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDividends(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'sometimes|uuid|exists:stocks,id',
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date|after_or_equal:start_date',
                'type' => 'sometimes|string|max:255',
                'upcoming_only' => 'sometimes|boolean',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $query = DividendHistory::query()
                ->with([
                    'stock:id,symbol,description,is_shariah',
                    'stock.sector:id,name',
                ]);

            // Filter by stock if provided
            if ($request->has('stock_id')) {
                $query->forStock($request->get('stock_id'));
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->forDateRange(
                    $request->get('start_date'),
                    $request->get('end_date')
                );
            }

            // Filter by type
            if ($request->has('type')) {
                $query->byType($request->get('type'));
            }

            // Filter upcoming only
            if ($request->boolean('upcoming_only', false)) {
                $query->upcoming();
            }

            // Order by pay date descending
            $query->orderByPayDate('desc');

            // Apply limit if specified
            if ($request->has('limit')) {
                $query->limit($request->get('limit'));
            }

            $dividends = $query->get();

            $data = [
                'dividends' => $dividends->map(function ($dividend) {
                    return [
                        'id' => $dividend->id,
                        'pay_date' => $dividend->pay_date->format('Y-m-d'),
                        'ex_date' => $dividend->ex_date->format('Y-m-d'),
                        'type' => $dividend->type,
                        'amount' => $dividend->amount,
                        'amount_split_adj' => $dividend->amount_split_adj,
                        'stock' => [
                            'id' => $dividend->stock->id,
                            'symbol' => $dividend->stock->symbol,
                            'description' => $dividend->stock->description,
                            'is_shariah' => $dividend->stock->is_shariah,
                            'sector' => $dividend->stock->sector ? [
                                'id' => $dividend->stock->sector->id,
                                'name' => $dividend->stock->sector->name,
                            ] : null,
                        ],
                    ];
                }),
                'total_count' => $dividends->count(),
                'total_amount' => $dividends->sum('amount'),
            ];

            return $this->successResponse($data, 'Dividends retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving dividends', $e);
        }
    }

    /**
     * Get upcoming dividends
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUpcomingDividends(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'sometimes|uuid|exists:stocks,id',
                'days_ahead' => 'sometimes|integer|min:1|max:365',
                'type' => 'sometimes|string|max:255',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $daysAhead = $request->get('days_ahead', 30);
            $endDate = now()->addDays($daysAhead)->format('Y-m-d');

            $query = DividendHistory::query()
                ->with([
                    'stock:id,symbol,description,is_shariah',
                    'stock.sector:id,name',
                ])
                ->upcoming()
                ->where('pay_date', '<=', $endDate);

            // Filter by stock if provided
            if ($request->has('stock_id')) {
                $query->forStock($request->get('stock_id'));
            }

            // Filter by type
            if ($request->has('type')) {
                $query->byType($request->get('type'));
            }

            // Apply limit if specified
            if ($request->has('limit')) {
                $query->limit($request->get('limit'));
            }

            $dividends = $query->get();

            $data = [
                'dividends' => $dividends->map(function ($dividend) {
                    return [
                        'id' => $dividend->id,
                        'pay_date' => $dividend->pay_date->format('Y-m-d'),
                        'ex_date' => $dividend->ex_date->format('Y-m-d'),
                        'type' => $dividend->type,
                        'amount' => $dividend->amount,
                        'amount_split_adj' => $dividend->amount_split_adj,
                        'stock' => [
                            'id' => $dividend->stock->id,
                            'symbol' => $dividend->stock->symbol,
                            'description' => $dividend->stock->description,
                            'is_shariah' => $dividend->stock->is_shariah,
                            'sector' => $dividend->stock->sector ? [
                                'id' => $dividend->stock->sector->id,
                                'name' => $dividend->stock->sector->name,
                            ] : null,
                        ],
                    ];
                }),
                'total_count' => $dividends->count(),
                'total_amount' => $dividends->sum('amount'),
                'date_range' => [
                    'start_date' => now()->format('Y-m-d'),
                    'end_date' => $endDate,
                ],
            ];

            return $this->successResponse($data, 'Upcoming dividends retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving upcoming dividends', $e);
        }
    }
}
