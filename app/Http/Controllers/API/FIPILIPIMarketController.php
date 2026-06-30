<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FIPILIPIMarketController extends Controller
{
    public function getMarketTypeView(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'investor_type' => 'sometimes|string|max:50',
                'market_type' => 'sometimes|string|max:150'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $query = DB::table('fipi_lipi_market_data')
                ->whereBetween('trade_date', [$request->get('start_date'), $request->get('end_date')]);

            $this->applyFilters($query, $request);

            $rawData = $query
                ->select([
                    'market_type',
                    'investor_type',
                    // FIXED: Use COALESCE to handle NULL values properly
                    DB::raw('COALESCE(SUM(buy_volume), 0) as buying_volume'),
                    DB::raw('COALESCE(SUM(buy_value), 0) as buying_value'),
                    DB::raw('COALESCE(SUM(sell_volume), 0) as selling_volume'),
                    DB::raw('COALESCE(SUM(sell_value), 0) as selling_value'),
                    DB::raw('COALESCE(SUM(net_value), 0) as net_buy_sell')
                ])
                ->groupBy('market_type', 'investor_type')
                ->orderBy('market_type')
                ->orderBy('investor_type')
                ->get();

            $marketTables = [];
            $grandTotal = [
                'buying_volume' => 0,
                'buying_value' => 0,
                'selling_volume' => 0,
                'selling_value' => 0,
                'net_buy_sell' => 0
            ];

            foreach ($rawData->groupBy('market_type') as $marketType => $investors) {
                // FIXED: Ensure proper type casting to float for calculations
                $marketTotal = [
                    'buying_volume' => (float) $investors->sum('buying_volume'),
                    'buying_value' => (float) $investors->sum('buying_value'),
                    'selling_volume' => (float) $investors->sum('selling_volume'),
                    'selling_value' => (float) $investors->sum('selling_value'),
                    'net_buy_sell' => (float) $investors->sum('net_buy_sell')
                ];

                $marketTables[] = [
                    'market_type' => $marketType,
                    'investor_rows' => $investors->map(function($item) {
                        return [
                            'investor_type' => $item->investor_type,
                            // FIXED: Cast to float to ensure numeric values
                            'buying_volume' => (float) $item->buying_volume,
                            'buying_value' => (float) $item->buying_value,
                            'selling_volume' => (float) $item->selling_volume,
                            'selling_value' => (float) $item->selling_value,
                            'net_buy_sell' => (float) $item->net_buy_sell
                        ];
                    })->values()->toArray(),
                    'market_total' => $marketTotal
                ];

                // FIXED: Accumulate grand total properly
                $grandTotal['buying_volume'] += $marketTotal['buying_volume'];
                $grandTotal['buying_value'] += $marketTotal['buying_value'];
                $grandTotal['selling_volume'] += $marketTotal['selling_volume'];
                $grandTotal['selling_value'] += $marketTotal['selling_value'];
                $grandTotal['net_buy_sell'] += $marketTotal['net_buy_sell'];
            }

            return $this->successResponse([
                'period' => [
                    'start_date' => $request->get('start_date'),
                    'end_date' => $request->get('end_date')
                ],
                'market_tables' => $marketTables,
                'grand_total' => $grandTotal
            ], 'Market type view data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving market type view data', $e);
        }
    }

    public function getInvestorView(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'investor_type' => 'sometimes|string|max:50',
                'market_type' => 'sometimes|string|max:150'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $query = DB::table('fipi_lipi_market_data')
                ->whereBetween('trade_date', [$request->get('start_date'), $request->get('end_date')]);

            $this->applyFilters($query, $request);

            $rawData = $query
                ->select([
                    'investor_type',
                    'market_type',
                    // FIXED: Use COALESCE to handle NULL values properly
                    DB::raw('COALESCE(SUM(buy_volume), 0) as buying_volume'),
                    DB::raw('COALESCE(SUM(buy_value), 0) as buying_value'),
                    DB::raw('COALESCE(SUM(sell_volume), 0) as selling_volume'),
                    DB::raw('COALESCE(SUM(sell_value), 0) as selling_value'),
                    DB::raw('COALESCE(SUM(net_value), 0) as net_buy_sell')
                ])
                ->groupBy('investor_type', 'market_type')
                ->orderBy('investor_type')
                ->orderBy('market_type')
                ->get();

            $investorTables = [];
            $grandTotal = [
                'buying_volume' => 0,
                'buying_value' => 0,
                'selling_volume' => 0,
                'selling_value' => 0,
                'net_buy_sell' => 0
            ];

            foreach ($rawData->groupBy('investor_type') as $investorType => $markets) {
                // FIXED: Ensure proper type casting to float for calculations
                $investorTotal = [
                    'buying_volume' => (float) $markets->sum('buying_volume'),
                    'buying_value' => (float) $markets->sum('buying_value'),
                    'selling_volume' => (float) $markets->sum('selling_volume'),
                    'selling_value' => (float) $markets->sum('selling_value'),
                    'net_buy_sell' => (float) $markets->sum('net_buy_sell')
                ];

                $investorTables[] = [
                    'investor_type' => $investorType,
                    'market_rows' => $markets->map(function($item) {
                        return [
                            'market_type' => $item->market_type,
                            // FIXED: Cast to float to ensure numeric values
                            'buying_volume' => (float) $item->buying_volume,
                            'buying_value' => (float) $item->buying_value,
                            'selling_volume' => (float) $item->selling_volume,
                            'selling_value' => (float) $item->selling_value,
                            'net_buy_sell' => (float) $item->net_buy_sell
                        ];
                    })->values()->toArray(),
                    'investor_total' => $investorTotal
                ];

                // FIXED: Accumulate grand total properly
                $grandTotal['buying_volume'] += $investorTotal['buying_volume'];
                $grandTotal['buying_value'] += $investorTotal['buying_value'];
                $grandTotal['selling_volume'] += $investorTotal['selling_volume'];
                $grandTotal['selling_value'] += $investorTotal['selling_value'];
                $grandTotal['net_buy_sell'] += $investorTotal['net_buy_sell'];
            }

            return $this->successResponse([
                'period' => [
                    'start_date' => $request->get('start_date'),
                    'end_date' => $request->get('end_date')
                ],
                'investor_tables' => $investorTables,
                'grand_total' => $grandTotal
            ], 'Investor view data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving investor view data', $e);
        }
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->has('investor_type') && !empty($request->get('investor_type'))) {
            // FIXED: Added empty check to prevent filtering with empty strings
            $query->where('investor_type', 'ILIKE', '%' . $request->get('investor_type') . '%');
        }

        if ($request->has('market_type') && !empty($request->get('market_type'))) {
            // FIXED: Added empty check to prevent filtering with empty strings
            $query->where('market_type', 'ILIKE', '%' . $request->get('market_type') . '%');
        }
    }
}
