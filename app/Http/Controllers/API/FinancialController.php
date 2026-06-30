<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FinancialController extends Controller
{
    /**
     * Get financial data for a specific stock
     *
     * @param string $symbol
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancialData(string $symbol, Request $request): JsonResponse
    {
        try {
            $stock = DB::table('stocks')->where('symbol', $symbol)->first();

            if (!$stock) {
                return $this->notFoundResponse('Stock not found');
            }

            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:QUARTERLY,ANNUAL'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->get('type');

            $financialData = DB::table('financial_data')
                ->where('symbol', $symbol)
                ->where('type', $type)
                ->select([
                    'id',
                    'identifier',
                    'value',
                    'statement',
                    'row_order',
                    'header',
                    'type',
                    'col_order',
                    'table_name',
                    'created_at',
                    'updated_at'
                ])
                ->orderBy('statement')
                ->orderBy('id')
                ->orderBy('row_order')
                ->orderBy('col_order')
                ->get();

            if ($financialData->isEmpty()) {
                return $this->successResponse([
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'type' => $type,
                    'statements' => []
                ], "No {$type} financial data found for {$symbol}");
            }

            $structuredData = [];
            $statementGroups = $financialData->groupBy('statement');

            foreach ($statementGroups as $statementName => $statementData) {
                $statement = [
                    'statement' => $statementName,
                    'tables' => []
                ];

                $tableGroups = $statementData->groupBy('table_name');
                $tableOrder = [];

                foreach ($tableGroups as $tableName => $tableData) {
                    $minId = $tableData->min('id');
                    $tableOrder[$tableName] = $minId;
                }

                asort($tableOrder);

                foreach ($tableOrder as $tableName => $minId) {
                    $tableData = $tableGroups[$tableName];

                    $headers = $tableData->pluck('header')
                        ->unique()
                        ->sortBy(function($header) use ($tableData) {
                            return $tableData->where('header', $header)->min('col_order');
                        })
                        ->values()
                        ->toArray();

                    $rows = [];
                    $rowGroups = $tableData->where('row_order', '>=', 0)
                        ->groupBy('row_order');

                    foreach ($rowGroups as $rowOrder => $rowData) {
                        $row = [];
                        $sortedRowData = $rowData->sortBy('col_order');

                        foreach ($sortedRowData as $cell) {
                            $row[] = [
                                'header' => $cell->header,
                                'value' => $cell->value,
                                'identifier' => $cell->identifier,
                                'col_order' => $cell->col_order
                            ];
                        }

                        $rows[] = $row;
                    }

                    $table = [
                        'table_name' => $tableName ?: 'DEFAULT',
                        'headers' => $headers,
                        'rows' => $rows,
                        'metadata' => [
                            'total_rows' => count($rows),
                            'total_columns' => count($headers),
                            'last_updated' => $tableData->max('updated_at')
                        ]
                    ];

                    $statement['tables'][] = $table;
                }

                $structuredData[] = $statement;
            }

            return $this->successResponse([
                'id' => $stock->id,
                'symbol' => $stock->symbol,
                'description' => $stock->description,
                'type' => $type,
                'statements' => $structuredData
            ], "Financial data retrieved successfully for {$symbol} ({$type})");

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving financial data', $e);
        }
    }

    /**
     * Get financial data by identifier for specific stocks with latest periods
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancialDataByIdentifier(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_ids' => 'sometimes|array',
                'stock_ids.*' => 'uuid',
                'identifier' => 'required|string|max:255',
                'period' => 'required|string|in:QUARTERLY,ANNUAL',
                'alignment_strategy' => 'sometimes|string|in:latest_available,standardized_periods,most_recent_common',
                'periods_count' => 'sometimes|integer|min:1|max:20'
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $stockIds = $request->get('stock_ids', []);
            $identifier = $request->get('identifier');
            $period = $request->get('period');
            $alignmentStrategy = $request->get('alignment_strategy', 'latest_available');
            $periodsCount = $request->get('periods_count', 8);

            // Build the base query
            $query = DB::table('financial_data as fd')
                ->join('stocks as s', 'fd.symbol', '=', 's.symbol')
                ->where('fd.identifier', $identifier)
                ->where('fd.type', $period)
                ->whereNotNull('fd.value')
                ->where('fd.value', '!=', '')
                ->where('fd.value', '!=', '0')
                ->where('fd.value', '!=', '-');

            // Apply stock filter if provided
            if (!empty($stockIds)) {
                $query->whereIn('s.id', $stockIds);
            }

            // Get all data first
            $allData = $query->select([
                's.id as stock_id',
                's.symbol',
                's.description',
                'fd.header',
                'fd.value',
                'fd.col_order',
                'fd.statement',
                'fd.table_name',
                'fd.created_at',
                'fd.updated_at'
            ])
                ->orderBy('s.symbol')
                ->orderBy('fd.col_order', 'desc')
                ->get();

            // For ANNUAL data, exclude TTM columns (highest col_order per stock)
            if ($period === 'ANNUAL') {
                $allData = $this->excludeTTMColumns($allData);
            }

            if ($allData->isEmpty()) {
                return $this->successResponse([
                    'identifier' => $identifier,
                    'period' => $period,
                    'alignment_strategy' => $alignmentStrategy,
                    'stocks' => [],
                    'summary' => [
                        'total_stocks' => 0,
                        'total_periods' => 0
                    ]
                ], "No financial data found for identifier: {$identifier}");
            }

            // Group by stock
            $stockGroups = $allData->groupBy('stock_id');

            // Apply alignment strategy
            $result = [];
            $periodAlignment = [];

            switch ($alignmentStrategy) {
                case 'standardized_periods':
                    [$result, $periodAlignment] = $this->alignByStandardizedPeriods($stockGroups, $identifier, $period, $periodsCount);
                    break;

                case 'most_recent_common':
                    [$result, $periodAlignment] = $this->alignByMostRecentCommon($stockGroups, $identifier, $periodsCount);
                    break;

                case 'latest_available':
                default:
                    [$result, $periodAlignment] = $this->alignByLatestAvailable($stockGroups, $identifier, $periodsCount);
                    break;
            }

            // Sort results by symbol
            usort($result, function ($a, $b) {
                return strcmp($a['symbol'], $b['symbol']);
            });

            return $this->successResponse([
                'identifier' => $identifier,
                'period' => $period,
                'alignment_strategy' => $alignmentStrategy,
                'requested_stock_ids' => $stockIds,
                'period_alignment' => $periodAlignment,
                'stocks' => $result,
            ], "Financial data retrieved successfully for identifier: {$identifier}");

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving financial data by identifier', $e);
        }
    }

    /**
     * Get all unique metrics from financial data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllMetrics(Request $request): JsonResponse
    {
        try {
            $metrics = DB::table('financial_data')
                ->select('identifier')
                ->distinct()
                ->whereNotNull('identifier')
                ->where('identifier', '!=', '')
                ->orderBy('identifier', 'asc')
                ->pluck('identifier');

            return $this->successResponse([
                'metrics' => $metrics,
                'total_metrics' => $metrics->count()
            ], 'All metrics retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving metrics', $e);
        }
    }

    /**
     * Get unique metrics for a specific stock by symbol or ID
     *
     * @param Request $request
     * @param string $stockIdentifier (can be symbol or UUID)
     * @return JsonResponse
     */
    public function getStockMetrics(Request $request, string $stockIdentifier): JsonResponse
    {
        try {
            // Check if the identifier is a UUID (stock ID) or a symbol
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $stockIdentifier);

            if ($isUuid) {
                // Get stock by ID
                $stock = DB::table('stocks')
                    ->where('id', $stockIdentifier)
                    ->first();
            } else {
                // Get stock by symbol
                $stock = DB::table('stocks')
                    ->where('symbol', 'ILIKE', $stockIdentifier)
                    ->first();
            }

            if (!$stock) {
                return $this->notFoundResponse('Stock not found');
            }

            // Get unique metrics for this stock's symbol
            $metrics = DB::table('financial_data')
                ->select('identifier', 'statement', 'type')
                ->distinct()
                ->where('symbol', $stock->symbol)
                ->whereNotNull('identifier')
                ->where('identifier', '!=', '')
                ->orderBy('statement', 'asc')
                ->orderBy('identifier', 'asc')
                ->get();

            // Group metrics by statement and type
            $groupedMetrics = $metrics->groupBy('statement')->map(function ($items) {
                return $items->groupBy('type')->map(function ($typeItems) {
                    return $typeItems->pluck('identifier')->unique()->values();
                });
            });

            return $this->successResponse([
                'stock' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description
                ],
                'metrics' => $metrics->pluck('identifier')->unique()->values(),
                'grouped_metrics' => $groupedMetrics,
                'total_metrics' => $metrics->pluck('identifier')->unique()->count()
            ], "Metrics retrieved successfully for {$stock->symbol}");

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock metrics', $e);
        }
    }

    /**
     * Exclude TTM columns (highest col_order per stock) for annual data
     *
     * @param \Illuminate\Support\Collection $allData
     * @return \Illuminate\Support\Collection
     */
    private function excludeTTMColumns($allData)
    {
        $stockGroups = $allData->groupBy('stock_id');
        $filteredData = collect();

        foreach ($stockGroups as $stockId => $stockData) {
            $maxColOrder = $stockData->max('col_order');
            $filteredStockData = $stockData->filter(function ($item) use ($maxColOrder) {
                return $item->col_order !== $maxColOrder;
            });
            $filteredData = $filteredData->merge($filteredStockData);
        }

        return $filteredData;
    }

    /**
     * Strategy 1: Latest Available
     */
    private function alignByLatestAvailable($stockGroups, $identifier, $periodsCount)
    {
        $result = [];
        $periodAlignment = [];

        foreach ($stockGroups as $stockId => $stockData) {
            $stockInfo = $stockData->first();
            $latestPeriods = $stockData->sortByDesc('col_order')
                ->unique('header')
                ->take($periodsCount)
                ->values();

            $periodsData = $latestPeriods->map(function ($item, $index) {
                return [
                    'header' => $item->header,
                    'value' => $item->value,
                    'col_order' => $item->col_order,
                    'statement' => $item->statement,
                    'table_name' => $item->table_name,
                    'relative_period' => "P-{$index}",
                    'last_updated' => $item->updated_at
                ];
            });

            $latestPeriod = $latestPeriods->first();
            if ($latestPeriod) {
                $periodAlignment[$latestPeriod->header] = ($periodAlignment[$latestPeriod->header] ?? 0) + 1;
            }

            $result[] = [
                'id' => $stockInfo->stock_id,
                'symbol' => $stockInfo->symbol,
                'description' => $stockInfo->description,
                'identifier' => $identifier,
                'periods' => $periodsData->toArray(),
                'last_updated' => $stockData->max('updated_at')
            ];
        }

        return [$result, $periodAlignment];
    }

    /**
     * Strategy 2: Standardized Periods
     */
    private function alignByStandardizedPeriods($stockGroups, $identifier, $period, $periodsCount)
    {
        $result = [];
        $standardPeriods = $this->generateStandardPeriods($period, $periodsCount);

        foreach ($stockGroups as $stockId => $stockData) {
            $stockInfo = $stockData->first();
            $periodsData = [];

            foreach ($standardPeriods as $standardPeriod) {
                $matchingData = $this->findMatchingPeriod($stockData, $standardPeriod);

                if ($matchingData) {
                    $periodsData[] = [
                        'header' => $matchingData->header,
                        'value' => $matchingData->value,
                        'col_order' => $matchingData->col_order,
                        'statement' => $matchingData->statement,
                        'table_name' => $matchingData->table_name,
                        'standard_period' => $standardPeriod,
                        'is_exact_match' => $matchingData->header === $standardPeriod,
                        'last_updated' => $matchingData->updated_at
                    ];
                } else {
                    $periodsData[] = [
                        'header' => null,
                        'value' => null,
                        'col_order' => null,
                        'statement' => null,
                        'table_name' => null,
                        'standard_period' => $standardPeriod,
                        'is_missing' => true
                    ];
                }
            }

            $result[] = [
                'id' => $stockInfo->stock_id,
                'symbol' => $stockInfo->symbol,
                'description' => $stockInfo->description,
                'identifier' => $identifier,
                'periods' => $periodsData,
                'last_updated' => $stockData->max('updated_at')
            ];
        }

        return [$result, $standardPeriods];
    }

    /**
     * Strategy 3: Most Recent Common
     */
    private function alignByMostRecentCommon($stockGroups, $identifier, $periodsCount)
    {
        $result = [];
        $periodCoverage = [];

        foreach ($stockGroups as $stockData) {
            $stockPeriods = $stockData->unique('header')->pluck('header')->toArray();
            foreach ($stockPeriods as $period) {
                $periodCoverage[$period] = ($periodCoverage[$period] ?? 0) + 1;
            }
        }

        $totalStocks = $stockGroups->count();
        $threshold = max(1, ceil($totalStocks * 0.7));

        $commonPeriods = collect($periodCoverage)
            ->filter(fn($count) => $count >= $threshold)
            ->keys()
            ->sortByDesc(fn($period) => $this->extractPeriodOrder($period))
            ->take($periodsCount)
            ->values()
            ->toArray();

        foreach ($stockGroups as $stockId => $stockData) {
            $stockInfo = $stockData->first();
            $periodsData = [];

            foreach ($commonPeriods as $period) {
                $periodData = $stockData->where('header', $period)->first();

                if ($periodData) {
                    $periodsData[] = [
                        'header' => $periodData->header,
                        'value' => $periodData->value,
                        'col_order' => $periodData->col_order,
                        'statement' => $periodData->statement,
                        'table_name' => $periodData->table_name,
                        'last_updated' => $periodData->updated_at
                    ];
                } else {
                    $periodsData[] = [
                        'header' => $period,
                        'value' => null,
                        'col_order' => null,
                        'statement' => null,
                        'table_name' => null,
                        'is_missing' => true,
                    ];
                }
            }

            $result[] = [
                'id' => $stockInfo->stock_id,
                'symbol' => $stockInfo->symbol,
                'description' => $stockInfo->description,
                'identifier' => $identifier,
                'periods' => $periodsData,
                'last_updated' => $stockData->max('updated_at')
            ];
        }

        return [$result, $commonPeriods];
    }

    /**
     * Generate standard periods
     */
    private function generateStandardPeriods($period, $count)
    {
        $periods = [];
        $currentYear = now()->year;
        $currentMonth = now()->month;

        if ($period === 'QUARTERLY') {
            $currentQuarter = ceil($currentMonth / 3);
            for ($i = 0; $i < $count; $i++) {
                $quarter = $currentQuarter - $i;
                $year = $currentYear;
                if ($quarter <= 0) {
                    $quarter += 4;
                    $year--;
                }
                $periods[] = "Q{$quarter} {$year}";
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $periods[] = (string)($currentYear - $i);
            }
        }

        return $periods;
    }

    /**
     * Find matching period
     */
    private function findMatchingPeriod($stockData, $standardPeriod)
    {
        $exactMatch = $stockData->where('header', $standardPeriod)->first();
        if ($exactMatch) return $exactMatch;

        return $stockData->first(function ($item) use ($standardPeriod) {
            return $this->periodsMatch($item->header, $standardPeriod);
        });
    }

    /**
     * Check if periods match
     */
    private function periodsMatch($period1, $period2)
    {
        return $this->normalizePeriodString($period1) === $this->normalizePeriodString($period2);
    }

    /**
     * Normalize period string
     */
    private function normalizePeriodString($period)
    {
        if (preg_match('/Q(\d)\s*(\d{4})|(\d{4})\s*Q(\d)/', strtoupper($period), $matches)) {
            $quarter = $matches[1] ?? $matches[4];
            $year = $matches[2] ?? $matches[3];
            return "Q{$quarter} {$year}";
        } elseif (preg_match('/(\d{4})/', $period, $matches)) {
            return $matches[1];
        }
        return strtoupper($period);
    }

    /**
     * Extract period order
     */
    private function extractPeriodOrder($period)
    {
        if (preg_match('/Q(\d)\s*(\d{4})/', $period, $matches)) {
            return ($matches[2] * 10) + $matches[1];
        } elseif (preg_match('/(\d{4})/', $period, $matches)) {
            return $matches[1] * 10;
        }
        return 0;
    }
}
