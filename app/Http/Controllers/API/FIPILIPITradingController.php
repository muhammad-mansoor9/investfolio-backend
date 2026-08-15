<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Helpers\CacheHelper;
use App\Services\FIPILIPIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FIPILIPITradingController extends Controller
{
    public function __construct(
        private FIPILIPIService $fipiLipiService,
    ) {}
    public function getSectorView(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'investor_type' => 'sometimes|string|max:150',
                'sector_name' => 'sometimes|string|max:150'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $allTradingData = $this->fipiLipiService->getTradingData($endDate);

            $filteredData = CacheHelper::normalize($allTradingData)
                ->filter(fn($item) => $item['trade_date'] >= $startDate && $item['trade_date'] <= $endDate);

            if ($filteredData->isEmpty()) {
                $query = DB::table('fipi_lipi_trading_data')
                    ->whereBetween('trade_date', [$startDate, $endDate]);

                $this->applyFilters($query, $request);

                $filteredData = $query->get()->map(fn($item) => (array) $item);
            }

            $grouped = $filteredData->groupBy(fn($item) => $item['sector_name'] . '|' . $item['investor_type'])
                ->map(function($group) {
                    $item = $group->first();
                    return [
                        'sector_name' => $item['sector_name'],
                        'investor_type' => $item['investor_type'],
                        'buying_volume' => $group->sum('buy_volume'),
                        'buying_value' => $group->sum('buy_value'),
                        'selling_volume' => $group->sum('sell_volume'),
                        'selling_value' => $group->sum('sell_value'),
                        'net_buy_sell' => $group->sum('net_value')
                    ];
                })
                ->values();

            $rawData = $grouped;

            $sectorTables = [];
            $grandTotal = [
                'buying_volume' => 0,
                'buying_value' => 0,
                'selling_volume' => 0,
                'selling_value' => 0,
                'net_buy_sell' => 0
            ];

            foreach ($rawData->groupBy('sector_name') as $sectorName => $investors) {
                $sectorTotal = [
                    'buying_volume' => $investors->sum('buying_volume'),
                    'buying_value' => $investors->sum('buying_value'),
                    'selling_volume' => $investors->sum('selling_volume'),
                    'selling_value' => $investors->sum('selling_value'),
                    'net_buy_sell' => $investors->sum('net_buy_sell')
                ];

                $sectorTables[] = [
                    'sector_name' => $sectorName,
                    'investor_rows' => $investors->map(function($item) {
                        return [
                            'investor_type' => $item['investor_type'],
                            'buying_volume' => $item['buying_volume'],
                            'buying_value' => $item['buying_value'],
                            'selling_volume' => $item['selling_volume'],
                            'selling_value' => $item['selling_value'],
                            'net_buy_sell' => $item['net_buy_sell']
                        ];
                    })->values()->toArray(),
                    'sector_total' => $sectorTotal
                ];

                $grandTotal['buying_volume'] += $sectorTotal['buying_volume'];
                $grandTotal['buying_value'] += $sectorTotal['buying_value'];
                $grandTotal['selling_volume'] += $sectorTotal['selling_volume'];
                $grandTotal['selling_value'] += $sectorTotal['selling_value'];
                $grandTotal['net_buy_sell'] += $sectorTotal['net_buy_sell'];
            }

            return $this->successResponse([
                'period' => [
                    'start_date' => $request->get('start_date'),
                    'end_date' => $request->get('end_date')
                ],
                'sector_tables' => $sectorTables,
                'grand_total' => $grandTotal
            ], 'Sector view data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector view data', $e);
        }
    }

    public function getInvestorView(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'investor_type' => 'sometimes|string|max:150',
                'sector_name' => 'sometimes|string|max:150'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $allTradingData = $this->fipiLipiService->getTradingData($endDate);

            $filteredData = CacheHelper::normalize($allTradingData)
                ->filter(fn($item) => $item['trade_date'] >= $startDate && $item['trade_date'] <= $endDate);

            if ($filteredData->isEmpty()) {
                $query = DB::table('fipi_lipi_trading_data')
                    ->whereBetween('trade_date', [$startDate, $endDate]);

                $this->applyFilters($query, $request);

                $filteredData = $query->get()->map(fn($item) => (array) $item);
            }

            $grouped = $filteredData->groupBy(fn($item) => $item['investor_type'] . '|' . $item['sector_name'])
                ->map(function($group) {
                    $item = $group->first();
                    return [
                        'investor_type' => $item['investor_type'],
                        'sector_name' => $item['sector_name'],
                        'buying_volume' => $group->sum('buy_volume'),
                        'buying_value' => $group->sum('buy_value'),
                        'selling_volume' => $group->sum('sell_volume'),
                        'selling_value' => $group->sum('sell_value'),
                        'net_buy_sell' => $group->sum('net_value')
                    ];
                })
                ->values();

            $rawData = $grouped;

            $investorTables = [];
            $grandTotal = [
                'buying_volume' => 0,
                'buying_value' => 0,
                'selling_volume' => 0,
                'selling_value' => 0,
                'net_buy_sell' => 0
            ];

            foreach ($rawData->groupBy('investor_type') as $investorType => $sectors) {
                $investorTotal = [
                    'buying_volume' => $sectors->sum('buying_volume'),
                    'buying_value' => $sectors->sum('buying_value'),
                    'selling_volume' => $sectors->sum('selling_volume'),
                    'selling_value' => $sectors->sum('selling_value'),
                    'net_buy_sell' => $sectors->sum('net_buy_sell')
                ];

                $investorTables[] = [
                    'investor_type' => $investorType,
                    'sector_rows' => $sectors->map(function($item) {
                        return [
                            'sector_name' => $item['sector_name'],
                            'buying_volume' => $item['buying_volume'],
                            'buying_value' => $item['buying_value'],
                            'selling_volume' => $item['selling_volume'],
                            'selling_value' => $item['selling_value'],
                            'net_buy_sell' => $item['net_buy_sell']
                        ];
                    })->values()->toArray(),
                    'investor_total' => $investorTotal
                ];

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
        if ($request->has('investor_type')) {
            $query->where('investor_type', 'ILIKE', '%' . $request->get('investor_type') . '%');
        }

        if ($request->has('sector_name')) {
            $query->where('sector_name', 'ILIKE', '%' . $request->get('sector_name') . '%');
        }
    }
}
