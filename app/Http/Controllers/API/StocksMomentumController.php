<?php

namespace App\Http\Controllers\API;

use App\Helpers\CacheHelper;
use App\Services\MansoorSpecialFilterService;
use App\Services\StockSignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StocksMomentumController extends BaseController
{
    public function __construct(
        private StockSignalService $signalService,
    ) {}
    private const SIGNAL_STATE_MAP = [
        'Bearish' => 1,
        'Weak' => 2,
        'Neutral' => 3,
        'Bullish' => 4,
        'Very Bullish' => 5,
    ];

    private const SIGNAL_WEIGHTS = [
        'ema_structure' => 0.40,
        'price_position' => 0.25,
        'rsi_momentum' => 0.20,
        'macd_confirmation' => 0.15,
    ];

    private const SIGNAL_METADATA = [
        'ema_6_gt_10' => [
            'name' => 'EMA 6/10 Crossover',
            'description' => '6-period EMA greater than 10-period EMA (faster vs slower moving average)',
            'states' => [
                'Very Bullish' => [
                    'title' => 'Golden Crossover Confirmed',
                    'description' => 'EMA6 above EMA10 with widening gap; momentum accelerating',
                ],
                'Bullish' => [
                    'title' => 'Golden Cross Confirmed',
                    'description' => 'EMA6 trading above EMA10; uptrend in place',
                ],
                'Neutral' => [
                    'title' => 'EMA Convergence',
                    'description' => 'EMA6 and EMA10 nearly equal; trend indecision',
                ],
                'Weak' => [
                    'title' => 'Death Cross Forming',
                    'description' => 'EMA6 falling below EMA10; uptrend breaking down',
                ],
                'Bearish' => [
                    'title' => 'Death Cross Confirmed',
                    'description' => 'EMA6 below EMA10 with confirmed lower lows; downtrend established',
                ],
            ],
        ],
        'ema_10_gt_21' => [
            'name' => 'EMA 10/21 Crossover',
            'description' => '10-period EMA greater than 21-period EMA (medium vs medium-long moving average)',
            'states' => [
                'Very Bullish' => [
                    'title' => 'Golden Crossover Confirmed',
                    'description' => 'EMA10 above EMA21 with widening gap; strong medium-term uptrend',
                ],
                'Bullish' => [
                    'title' => 'Golden Cross Confirmed',
                    'description' => 'EMA10 trading above EMA21; medium-term uptrend in place',
                ],
                'Neutral' => [
                    'title' => 'EMA Convergence',
                    'description' => 'EMA10 and EMA21 nearly equal; medium-term trend in question',
                ],
                'Weak' => [
                    'title' => 'Death Cross Forming',
                    'description' => 'EMA10 falling below EMA21; medium-term uptrend breaking down',
                ],
                'Bearish' => [
                    'title' => 'Death Cross Confirmed',
                    'description' => 'EMA10 below EMA21; medium-term downtrend established',
                ],
            ],
        ],
        'ema_10w_gt_21w' => [
            'name' => 'EMA 10W/21W Crossover',
            'description' => 'Weekly EMA10 greater than EMA21 (medium vs long-term weekly moving average)',
            'states' => [
                'Very Bullish' => [
                    'title' => 'Golden Crossover Confirmed',
                    'description' => 'EMA10W above EMA21W with widening gap; strong long-term uptrend',
                ],
                'Bullish' => [
                    'title' => 'Golden Cross Confirmed',
                    'description' => 'EMA10W trading above EMA21W; long-term uptrend established',
                ],
                'Neutral' => [
                    'title' => 'EMA Convergence',
                    'description' => 'EMA10W and EMA21W nearly equal; long-term direction uncertain',
                ],
                'Weak' => [
                    'title' => 'Death Cross Forming',
                    'description' => 'EMA10W falling below EMA21W; long-term uptrend at risk',
                ],
                'Bearish' => [
                    'title' => 'Death Cross Confirmed',
                    'description' => 'EMA10W below EMA21W; long-term downtrend established',
                ],
            ],
        ],
        'rsi_momentum' => [
            'name' => 'RSI Momentum',
            'description' => 'RSI(14) with 9-period Signal Line - 3-state signal (Bullish/Neutral/Bearish)',
            'states' => [
                'Bullish' => [
                    'title' => 'Bullish Momentum',
                    'description' => 'RSI ≥ 60 AND RSI > Signal line. Strong bullish momentum.',
                ],
                'Neutral' => [
                    'title' => 'Neutral Zone',
                    'description' => 'RSI between 40-60. Mixed momentum, consolidation.',
                ],
                'Bearish' => [
                    'title' => 'Bearish Momentum',
                    'description' => 'RSI < 40 AND RSI < Signal line. Strong bearish momentum.',
                ],
            ],
        ],
        'macd_confirmation' => [
            'name' => 'MACD Confirmation',
            'description' => 'MACD(12,26,9) - 3-state signal (Bullish/Neutral/Bearish)',
            'states' => [
                'Bullish' => [
                    'title' => 'Bullish Momentum',
                    'description' => 'MACD ≥ 0 AND MACD > Signal line. Bullish momentum confirmed.',
                ],
                'Neutral' => [
                    'title' => 'Transition Zone',
                    'description' => 'MACD between -0.5 and 0. Momentum cooling, transition phase.',
                ],
                'Bearish' => [
                    'title' => 'Bearish Momentum',
                    'description' => 'MACD < 0 AND MACD < Signal line. Bearish momentum confirmed.',
                ],
            ],
        ],
        'price_position_ema6' => [
            'name' => 'Price > EMA6 (Trend Health)',
            'description' => 'Close > EMA6 (BULLISH) or Close ≤ EMA6 (BEARISH) on daily bars',
            'states' => [
                'Bullish' => [
                    'title' => 'Above Short-term EMA',
                    'description' => 'Close > EMA6. Daily price above short-term EMA, confirms uptrend health.',
                ],
                'Bearish' => [
                    'title' => 'Below Short-term EMA',
                    'description' => 'Close ≤ EMA6. Daily price below short-term EMA, signals weakening trend.',
                ],
            ],
        ],
        'price_position_ema10' => [
            'name' => 'Price > EMA10 (Trend Health)',
            'description' => 'Close > EMA10 (BULLISH) or Close ≤ EMA10 (BEARISH) on daily bars',
            'states' => [
                'Bullish' => [
                    'title' => 'Above Medium-term EMA',
                    'description' => 'Close > EMA10. Daily price above medium-term EMA, confirms uptrend health.',
                ],
                'Bearish' => [
                    'title' => 'Below Medium-term EMA',
                    'description' => 'Close ≤ EMA10. Daily price below medium-term EMA, signals weakening trend.',
                ],
            ],
        ],
        'price_position_ema10w' => [
            'name' => 'Price > EMA10W (Trend Health)',
            'description' => 'Close > EMA10W (BULLISH) or Close ≤ EMA10W (BEARISH) on weekly bars',
            'states' => [
                'Bullish' => [
                    'title' => 'Above Weekly Medium-term EMA',
                    'description' => 'Close > EMA10W. Weekly price above medium-term EMA, confirms institutional uptrend.',
                ],
                'Bearish' => [
                    'title' => 'Below Weekly Medium-term EMA',
                    'description' => 'Close ≤ EMA10W. Weekly price below medium-term EMA, signals trend breakdown.',
                ],
            ],
        ],
        'price_position_ema21w' => [
            'name' => 'Price > EMA21W (Long-term Support)',
            'description' => 'Close > EMA21W (BULLISH) or Close ≤ EMA21W (BEARISH) on weekly bars',
            'states' => [
                'Bullish' => [
                    'title' => 'Above Weekly Long-term EMA',
                    'description' => 'Close > EMA21W. Weekly price above long-term EMA, confirms strong institutional support.',
                ],
                'Bearish' => [
                    'title' => 'Below Weekly Long-term EMA',
                    'description' => 'Close ≤ EMA21W. Weekly price below long-term EMA, signals loss of long-term support.',
                ],
            ],
        ],
        'price_position_ema10m' => [
            'name' => 'Price > EMA10M (Very Long-term Support)',
            'description' => 'Close > EMA10M (BULLISH) or Close ≤ EMA10M (BEARISH) on monthly bars',
            'states' => [
                'Bullish' => [
                    'title' => 'Above Monthly Medium-term EMA',
                    'description' => 'Close > EMA10M. Monthly price above medium-term EMA, confirms very long-term uptrend.',
                ],
                'Bearish' => [
                    'title' => 'Below Monthly Medium-term EMA',
                    'description' => 'Close ≤ EMA10M. Monthly price below medium-term EMA, signals very long-term trend breakdown.',
                ],
            ],
        ],
    ];

    public function getStockSignals(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'sometimes|date_format:Y-m-d',
                'mansoor_special' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $requestedDate = $request->get('date');
            $mansoorSpecial = $request->boolean('mansoor_special', false);

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                if (!$mansoorService->isAuthorized($request)) {
                    return $mansoorService->getAuthorizationError();
                }
            }

            $strategies = ['explosive', 'swing', 'positional'];
            $cutoffDate = $requestedDate ?? date('Y-m-d');
            $resolvedDates = [];

            $signalCollections = [];
            foreach ($strategies as $strategy) {
                $cachedSignals = $this->signalService->getSignalsByStrategy($strategy, $cutoffDate);
                $collection = collect($cachedSignals);

                if ($collection->isEmpty()) {
                    $latestSignalDates = DB::table('stock_signals')
                        ->select('stock_id', 'signal_name', DB::raw('MAX(signal_date) as latest_date'))
                        ->where('strategy', $strategy)
                        ->where('signal_date', '<=', $cutoffDate)
                        ->whereNotIn('signal_name', ['price_position_ema21w'])
                        ->groupBy('stock_id', 'signal_name');

                    $strategySignals = DB::table('stock_signals as ss')
                        ->join('stocks as s', 'ss.stock_id', '=', 's.id')
                        ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                        ->select([
                            'ss.stock_id',
                            's.symbol',
                            's.description as company_name',
                            's.is_shariah',
                            'sec.id as sector_id',
                            'sec.name as sector_name',
                            'ss.strategy',
                            'ss.signal_name',
                            'ss.signal_state',
                            'ss.signal_value',
                            'ss.metadata',
                            'ss.signal_date',
                        ])
                        ->where('ss.strategy', $strategy)
                        ->where('s.is_active', true)
                        ->where('s.market_cap', '>', 0)
                        ->whereNotIn('ss.signal_name', ['price_position_ema21w'])
                        ->joinSub($latestSignalDates, 'latest', function($join) {
                            $join->on('ss.stock_id', '=', 'latest.stock_id')
                                 ->on('ss.signal_name', '=', 'latest.signal_name')
                                 ->on('ss.signal_date', '=', 'latest.latest_date');
                        });

                    if ($mansoorSpecial) {
                        $strategySignals->leftJoin('stock_prices as sp', function($join) {
                            $join->on('ss.stock_id', '=', 'sp.stock_id')
                                 ->on('sp.date', '=', DB::raw("(
                                    SELECT MAX(date) FROM stock_prices sp2
                                    WHERE sp2.stock_id = ss.stock_id
                                 )"));
                        });

                        $mansoorService = new MansoorSpecialFilterService();
                        $strategySignals->whereRaw($mansoorService->getStocksWhereClause());
                    }

                    $collection = $strategySignals->get();
                }

                if ($collection->isNotEmpty()) {
                    $collection = CacheHelper::normalize($collection);

                    if ($mansoorSpecial) {
                        $mansoorService = new MansoorSpecialFilterService();
                        $collection = $collection->filter(function($signal) use ($mansoorService) {
                            $stock = DB::table('stocks')
                                ->where('id', $signal['stock_id'])
                                ->first();
                            if (!$stock) return false;

                            return $mansoorService->passesFilter($stock);
                        });
                    }

                    if ($collection->isNotEmpty()) {
                        $resolvedDates[$strategy] = $collection->max(fn($item) => $item['signal_date']);
                        $signalCollections[] = $collection;
                    }
                }
            }

            if (empty($signalCollections)) {
                return $this->successResponse([
                    'date' => $requestedDate,
                    'total_results' => 0,
                    'data' => [],
                ], 'No stock signal data found');
            }

            $signals = collect();
            foreach ($signalCollections as $collection) {
                $signals = $signals->merge($collection);
            }

            if (empty($signals)) {
                return $this->successResponse([
                    'date' => $requestedDate,
                    'total_results' => 0,
                    'data' => [],
                ], 'No stock signals for the selected date');
            }

            $stockMap = [];
            foreach ($signals as $signal) {

                $key = $signal['stock_id'];
                if (!isset($stockMap[$key])) {
                    $stockMap[$key] = [
                        'stock_id' => $signal['stock_id'],
                        'symbol' => $signal['symbol'],
                        'company_name' => $signal['company_name'],
                        'sector_id' => $signal['sector_id'],
                        'sector_name' => $signal['sector_name'],
                        'is_shariah' => (bool)$signal['is_shariah'],
                        'strategies' => [
                            'explosive' => ['signals' => []],
                            'swing' => ['signals' => []],
                            'positional' => ['signals' => []],
                        ],
                    ];
                }

                $signalState = trim($signal['signal_state']);
                if (isset(self::SIGNAL_STATE_MAP[$signalState])) {
                    $decodedMetadata = [];
                    if (!empty($signal['metadata'])) {
                        $decodedMetadata = json_decode($signal['metadata'], true);
                        if (!is_array($decodedMetadata)) {
                            $decodedMetadata = [];
                        }
                    }

                    $signalData = [
                        'state' => $signalState,
                        'value' => self::SIGNAL_STATE_MAP[$signalState],
                        'actual_value' => !empty($signal['signal_value']) ? (float)$signal['signal_value'] : null,
                        'metadata' => $decodedMetadata,
                        'signal_date' => $signal['signal_date'],
                    ];

                    $stockMap[$key]['strategies'][$signal['strategy']]['signals'][$signal['signal_name']] = $signalData;
                }
            }

            $data = [];
            foreach ($stockMap as $stock) {
                $stock['strategies'] = $this->calculateStrategyScores($stock['strategies']);
                $data[] = $stock;
            }

            usort($data, fn($a, $b) => strcasecmp($a['symbol'], $b['symbol']));

            return $this->successResponse([
                'date' => $requestedDate ?? date('Y-m-d'),
                'resolved_dates' => $resolvedDates,
                'total_results' => count($data),
                'metadata' => self::SIGNAL_METADATA,
                'data' => $data,
            ], 'Stock signals retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock signals', $e);
        }
    }

    public function debugSignals(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'sometimes|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $requestedDate = $request->get('date');

            $resolvedDate = $requestedDate
                ? DB::table('stock_signals')->where('signal_date', '<=', $requestedDate)->max('signal_date')
                : DB::table('stock_signals')->max('signal_date');

            // Get all unique strategies and their signals for the resolved date
            $allStrategies = DB::table('stock_signals as ss')
                ->select(['ss.strategy', 'ss.signal_name'])
                ->distinct()
                ->where('ss.signal_date', $resolvedDate)
                ->orderBy('ss.strategy')
                ->orderBy('ss.signal_name')
                ->get();

            // Organize by strategy
            $signalsByStrategy = [];
            foreach ($allStrategies as $row) {
                if (!isset($signalsByStrategy[$row->strategy])) {
                    $signalsByStrategy[$row->strategy] = [];
                }
                $signalsByStrategy[$row->strategy][] = $row->signal_name;
            }

            // Get sample data from each strategy (one stock per strategy)
            $sampleData = [];
            foreach (array_keys($signalsByStrategy) as $strategy) {
                $sample = DB::table('stock_signals as ss')
                    ->join('stocks as s', 'ss.stock_id', '=', 's.id')
                    ->select([
                        's.symbol',
                        'ss.strategy',
                        'ss.signal_name',
                        'ss.signal_state',
                    ])
                    ->where('ss.signal_date', $resolvedDate)
                    ->where('ss.strategy', $strategy)
                    ->orderBy('s.symbol')
                    ->limit(1)
                    ->get();

                if ($sample->isNotEmpty()) {
                    $sampleData[$strategy] = $sample->toArray();
                }
            }

            // Build response
            $signals = [];
            foreach ($signalsByStrategy as $strategy => $columns) {
                $signals[] = [
                    'type' => $strategy,
                    'unique_signal_columns' => $columns,
                    'sample_data' => $sampleData[$strategy] ?? [],
                ];
            }

            // Get total stocks with data for this date
            $totalStocksWithSignals = DB::table('stock_signals as ss')
                ->join('stocks as s', 'ss.stock_id', '=', 's.id')
                ->where('ss.signal_date', $resolvedDate)
                ->select('s.symbol')
                ->distinct()
                ->count();

            return $this->successResponse([
                'resolved_date' => $resolvedDate,
                'signals' => $signals,
                'total_stocks_with_signals' => $totalStocksWithSignals,
                'total_signal_records' => DB::table('stock_signals')->where('signal_date', $resolvedDate)->count(),
            ], 'Debug info');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Debug error', $e);
        }
    }

    private function calculateStrategyScores(array $strategies): array
    {
        foreach ($strategies as $strategy => $data) {
            $signals = $data['signals'] ?? [];

            // Map signal names to signal types (ema_structure, price_position, etc.)
            $normalizedSignals = $this->normalizeSignals($signals);

            // Calculate weighted score with trend veto
            $score = $this->calculateWeightedScore($normalizedSignals);

            $signalsAvailable = count($signals);

            $signalDetails = [];
            foreach ($signals as $signalName => $signalData) {
                $state = $signalData['state'] ?? null;
                if ($state && isset(self::SIGNAL_STATE_MAP[$state])) {
                    $signalDetails[$signalName] = [
                        'state' => $state,
                        'value' => self::SIGNAL_STATE_MAP[$state],
                    ];

                    if (isset($signalData['actual_value'])) {
                        $signalDetails[$signalName]['actual_value'] = $signalData['actual_value'];
                    }
                    if (isset($signalData['metadata'])) {
                        $signalDetails[$signalName]['metadata'] = $signalData['metadata'];
                    }
                    if (isset($signalData['signal_date'])) {
                        $signalDetails[$signalName]['signal_date'] = $signalData['signal_date'];
                    }
                }
            }

            $strategies[$strategy] = [
                'score' => round($score, 2),
                'signals_available' => $signalsAvailable,
                'signals' => $signalDetails,
            ];
        }

        return $strategies;
    }

    private function normalizeSignals(array $signals): array
    {
        $normalized = [];

        foreach ($signals as $signalName => $signalData) {

            $state = $signalData['state'] ?? null;
            if (!$state || !isset(self::SIGNAL_STATE_MAP[$state])) {
                continue;
            }

            // Map signal name to signal type
            if (strpos($signalName, 'ema') === 0) {
                $type = 'ema_structure';
            } elseif (strpos($signalName, 'price') === 0) {
                $type = 'price_position';
            } elseif (strpos($signalName, 'rsi') === 0) {
                $type = 'rsi_momentum';
            } elseif (strpos($signalName, 'macd') === 0) {
                $type = 'macd_confirmation';
            } else {
                continue; // Skip unknown signal types
            }

            $normalized[$type] = [
                'state' => $state,
                'value' => self::SIGNAL_STATE_MAP[$state],
            ];
        }

        return $normalized;
    }

    private function calculateWeightedScore(array $normalizedSignals): float
    {
        // v6.0: Binary signals (EMA, Price) + 3-state signals (RSI, MACD)
        $binaryToScore = [
            'Bullish' => 100,
            'Bearish' => 0,
        ];

        $multiStateToScore = [
            'Bullish' => 100,
            'Neutral' => 50,
            'Bearish' => 0,
        ];

        // If no EMA structure signal, can't calculate meaningful score
        if (!isset($normalizedSignals['ema_structure'])) {
            return 0;
        }

        $weightedSum = 0;
        $totalWeight = 0;

        // Calculate weighted sum
        foreach ($normalizedSignals as $type => $data) {
            $state = $data['state'];
            $weight = self::SIGNAL_WEIGHTS[$type] ?? 0;

            // Binary signals: EMA Structure, Price Position
            if ($type === 'ema_structure' || $type === 'price_position') {
                $numericScore = $binaryToScore[$state] ?? 0;
            } else {
                // 3-state signals: RSI Momentum, MACD Confirmation (v6.0)
                $numericScore = $multiStateToScore[$state] ?? 50;
            }

            $weightedSum += $numericScore * $weight;
            $totalWeight += $weight;
        }

        // Normalize by total weight (in case some signals are missing)
        // Formula: (EMA×0.40 + Price×0.25 + RSI×0.20 + MACD×0.15)
        // Scoring: EMA/Price (100 or 0) + RSI/MACD (100, 50, or 0)
        $overallScore = $totalWeight > 0 ? ($weightedSum / $totalWeight) : 0;

        return min(100, max(0, $overallScore));
    }

}
