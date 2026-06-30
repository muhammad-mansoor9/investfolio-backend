<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StocksAnalysisController extends BaseController
{
    private const DEFAULT_IDENTIFIERS = [
        'Total Revenues',
        'Gross Profit Margin',
        'Operating Income',
        'Net Income',
        'Earnings Per Share - Basic',
        'Return on Assets',
        'Return on Equity'
    ];

    public function getLatestQuarterlyAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sector_id' => 'sometimes|uuid',
                'shariah_compliant' => 'sometimes|boolean',
                'identifiers' => 'sometimes|array',
                'identifiers.*' => 'string',
                'limit' => 'sometimes|integer|min:1|max:1000'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $sectorId = $request->get('sector_id');
            $shariahCompliant = $request->get('shariah_compliant');
            $identifiers = $request->get('identifiers', self::DEFAULT_IDENTIFIERS);
            $limit = $request->get('limit', 100);

            // Get the latest quarter's max col_order
            $latestQuarter = DB::table('financial_data')
                ->where('type', 'QUARTERLY')
                ->max('col_order');

            // Build base query
            $query = DB::table('financial_data as fd')
                ->join('stocks as s', 'fd.symbol', '=', 's.symbol')
                ->where('fd.type', 'QUARTERLY')
                ->where('fd.col_order', $latestQuarter)
                ->whereIn('fd.identifier', $identifiers)
                ->select([
                    's.id as stock_id',
                    's.symbol',
                    's.description',
                    's.sector_id',
                    'sec.name as sector_name',
                    's.is_shariah',
                    'fd.identifier',
                    'fd.value',
                    'fd.header as quarter_name'
                ])
                ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id');

            // Apply filters
            if ($sectorId) {
                $query->where('s.sector_id', $sectorId);
            }

            if ($shariahCompliant !== null) {
                $query->where('s.is_shariah', $shariahCompliant);
            }

            $data = $query->limit($limit)->get();

            // Pivot data: group by stock, array of identifiers as columns
            $pivotedData = [];
            foreach ($data as $row) {
                $key = $row->stock_id;
                if (!isset($pivotedData[$key])) {
                    $pivotedData[$key] = [
                        'stock_id' => $row->stock_id,
                        'symbol' => $row->symbol,
                        'name' => $row->description,
                        'sector_id' => $row->sector_id,
                        'sector_name' => $row->sector_name,
                        'shariah_compliant' => $row->is_shariah,
                        'quarter_name' => $row->quarter_name,
                        'metrics' => []
                    ];
                }
                $pivotedData[$key]['metrics'][$row->identifier] = $row->value;
            }

            return $this->successResponse([
                'stocks' => array_values($pivotedData),
                'total_count' => count($pivotedData),
                'identifiers' => $identifiers,
                'quarter_name' => $data->first()?->quarter_name ?? 'N/A'
            ], 'Latest quarterly analysis retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stocks analysis', $e);
        }
    }

    public function getSectorLeadership(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sector_id' => 'required|uuid',
                'identifiers' => 'sometimes|array',
                'identifiers.*' => 'string'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $sectorId = $request->get('sector_id');
            $identifiers = $request->get('identifiers', self::DEFAULT_IDENTIFIERS);

            // Get latest and previous quarters' col_order
            $quarters = DB::table('financial_data')
                ->where('type', 'QUARTERLY')
                ->distinct()
                ->orderBy('col_order', 'desc')
                ->limit(2)
                ->pluck('col_order')
                ->values()
                ->toArray();

            if (count($quarters) < 2) {
                return $this->successResponse([
                    'sector_id' => $sectorId,
                    'latest_quarter' => $quarters[0] ?? null,
                    'previous_quarter' => null,
                    'stocks' => []
                ], 'Sector leadership data retrieved (insufficient quarters available)');
            }

            $latestQuarter = $quarters[0];
            $previousQuarter = $quarters[1];

            // Get stocks in sector
            $stocks = DB::table('stocks')
                ->where('sector_id', $sectorId)
                ->select(['id', 'symbol', 'description', 'sector_id'])
                ->get();

            $stockIds = $stocks->pluck('symbol')->toArray();

            if (empty($stockIds)) {
                return $this->successResponse([
                    'sector_id' => $sectorId,
                    'latest_quarter' => $latestQuarter,
                    'previous_quarter' => $previousQuarter,
                    'stocks' => []
                ], 'No stocks found in this sector');
            }

            // Get latest quarter data
            $latestData = DB::table('financial_data as fd')
                ->where('fd.type', 'QUARTERLY')
                ->where('fd.col_order', $latestQuarter)
                ->whereIn('fd.symbol', $stockIds)
                ->whereIn('fd.identifier', $identifiers)
                ->select([
                    'fd.symbol',
                    'fd.identifier',
                    'fd.value',
                    'fd.header as quarter_name'
                ])
                ->get()
                ->groupBy('symbol');

            // Get previous quarter data
            $previousData = DB::table('financial_data as fd')
                ->where('fd.type', 'QUARTERLY')
                ->where('fd.col_order', $previousQuarter)
                ->whereIn('fd.symbol', $stockIds)
                ->whereIn('fd.identifier', $identifiers)
                ->select([
                    'fd.symbol',
                    'fd.identifier',
                    'fd.value',
                    'fd.header as quarter_name'
                ])
                ->get()
                ->groupBy('symbol');

            // Structure response
            $results = [];
            foreach ($stocks as $stock) {
                $latest = $latestData->get($stock->symbol, collect());
                $previous = $previousData->get($stock->symbol, collect());

                $latestMetrics = [];
                foreach ($latest as $row) {
                    $latestMetrics[$row->identifier] = $row->value;
                }

                $previousMetrics = [];
                foreach ($previous as $row) {
                    $previousMetrics[$row->identifier] = $row->value;
                }

                $results[] = [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->description,
                    'latest_quarter' => [
                        'metrics' => $latestMetrics,
                        'quarter_name' => $latest->first()?->quarter_name ?? 'N/A'
                    ],
                    'previous_quarter' => [
                        'metrics' => $previousMetrics,
                        'quarter_name' => $previous->first()?->quarter_name ?? 'N/A'
                    ]
                ];
            }

            return $this->successResponse([
                'sector_id' => $sectorId,
                'identifiers' => $identifiers,
                'stocks' => $results,
                'total_count' => count($results)
            ], 'Sector leadership data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector leadership data', $e);
        }
    }
}
