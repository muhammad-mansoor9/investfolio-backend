<?php

namespace App\Http\Controllers\API;

use App\Services\MansoorSpecialFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StocksMomentumController extends BaseController
{
    private const SIGNAL_STATE_MAP = [
        'Bearish' => 1,
        'Weak' => 2,
        'Neutral' => 3,
        'Bullish' => 4,
        'Very Bullish' => 5,
    ];

    private const SIGNAL_METADATA = [
        'ema_6_gt_10' => [
            'name' => 'EMA 6/10 Crossover',
            'description' => '6-period EMA greater than 10-period EMA (faster vs slower moving average)',
            'purpose' => 'Identifies short-term trend initiation and momentum shifts',
            'state_interpretations' => [
                'Bullish' => '6-period EMA is above 10-period EMA, indicating upward momentum',
                'Bearish' => '6-period EMA is below 10-period EMA, indicating downward momentum',
                'Neutral' => 'EMAs are converging or diverging near parity',
                'Weak' => 'Signal conflicting with other indicators',
            ],
            'calculation_details' => 'Compares exponential moving average (period=6) to EMA (period=10)',
        ],
        'ema_10_gt_21' => [
            'name' => 'EMA 10/21 Crossover',
            'description' => '10-period EMA greater than 21-period EMA (medium vs medium-long moving average)',
            'purpose' => 'Confirms intermediate trend strength and sustainability',
            'state_interpretations' => [
                'Bullish' => '10-period EMA is above 21-period EMA, showing sustained uptrend',
                'Bearish' => '10-period EMA is below 21-period EMA, showing sustained downtrend',
                'Neutral' => 'EMAs near parity with uncertain direction',
                'Weak' => 'Conflicting signals suggesting potential trend reversal',
            ],
            'calculation_details' => 'Compares exponential moving average (period=10) to EMA (period=21)',
        ],
        'adx_strength' => [
            'name' => 'ADX Trend Strength',
            'description' => 'Average Directional Index measuring the strength of a trend regardless of direction',
            'purpose' => 'Quantifies trend strength and identifies when trend is weakening',
            'state_interpretations' => [
                'Bullish' => 'ADX > 25 with DI+ > DI-, strong uptrend with increasing strength',
                'Bearish' => 'ADX > 25 with DI- > DI+, strong downtrend with increasing strength',
                'Neutral' => 'ADX between 20-25, trend is present but not yet strong',
                'Weak' => 'ADX < 20, no clear trend or trend losing momentum',
            ],
            'calculation_details' => 'ADX derived from +DI and -DI over 14 periods',
        ],
        'rsi_momentum' => [
            'name' => 'RSI Momentum',
            'description' => 'Relative Strength Index measuring overbought/oversold conditions and momentum',
            'purpose' => 'Identifies momentum extremes and potential reversal points',
            'state_interpretations' => [
                'Bullish' => 'RSI 50-70: Strong upward momentum without overbought extreme',
                'Very Bullish' => 'RSI > 70: Overbought condition, potential for sharp pullback',
                'Neutral' => 'RSI 40-60: Balanced momentum, no clear direction',
                'Weak' => 'RSI 30-40: Downward momentum building without extreme oversold',
                'Bearish' => 'RSI < 30: Oversold condition, potential for sharp bounce',
            ],
            'calculation_details' => 'RSI calculated over 14 periods: 100 - (100 / (1 + RS)) where RS = avg gains / avg losses',
        ],
        'macd_confirmation' => [
            'name' => 'MACD Trend Confirmation',
            'description' => 'Moving Average Convergence Divergence confirming trend direction and momentum',
            'purpose' => 'Validates trend signals and identifies momentum shifts via histogram',
            'state_interpretations' => [
                'Bullish' => 'MACD line above signal line with positive/growing histogram, uptrend confirmed',
                'Neutral' => 'MACD and signal lines converging or recently crossed, transition period',
                'Weak' => 'MACD below signal line with declining histogram, downtrend confirmed but weakening',
                'Bearish' => 'MACD line below signal line with negative histogram, downtrend confirmed',
            ],
            'calculation_details' => 'MACD = EMA(12) - EMA(26), Signal = EMA(9) of MACD, Histogram = MACD - Signal',
        ],
    ];

    public function getStockSignals(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'sometimes|date_format:Y-m-d',
                'shariah_only' => 'sometimes|boolean',
                'mansoor_special' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $requestedDate = $request->get('date');
            $shariahOnly = $request->boolean('shariah_only', false);
            $mansoorSpecial = $request->boolean('mansoor_special', false);

            if ($mansoorSpecial) {
                $mansoorService = new MansoorSpecialFilterService();
                if (!$mansoorService->isAuthorized($request)) {
                    return $mansoorService->getAuthorizationError();
                }
            }

            $strategies = ['explosive', 'swing', 'positional'];
            $resolvedDates = [];

            foreach ($strategies as $strategy) {
                $latestDate = DB::table('stock_signals')
                    ->where('strategy', $strategy)
                    ->where('signal_date', '<=', $requestedDate ?? date('Y-m-d'))
                    ->max('signal_date');

                if ($latestDate) {
                    $resolvedDates[$strategy] = $latestDate;
                }
            }

            if (empty($resolvedDates)) {
                return $this->successResponse([
                    'date' => $requestedDate,
                    'total_results' => 0,
                    'data' => [],
                ], 'No stock signal data found');
            }

            $signalCollections = [];
            foreach ($resolvedDates as $strategy => $strategyDate) {
                $strategySignals = DB::table('stock_signals as ss')
                    ->join('stocks as s', 'ss.stock_id', '=', 's.id')
                    ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
                    ->select([
                        'ss.stock_id',
                        'ss.symbol',
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
                    ->where('ss.signal_date', $strategyDate)
                    ->where('s.is_active', true)
                    ->where('s.market_cap', '>', 0);

                if ($shariahOnly || $mansoorSpecial) {
                    $strategySignals->where('s.is_shariah', true);
                }

                if ($mansoorSpecial) {
                    $mansoorService = new MansoorSpecialFilterService();
                    $strategySignals->whereRaw($mansoorService->getStocksWhereClause());
                }

                $signalCollections[] = $strategySignals->get();
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
                $key = $signal->stock_id;
                if (!isset($stockMap[$key])) {
                    $stockMap[$key] = [
                        'stock_id' => $signal->stock_id,
                        'symbol' => $signal->symbol,
                        'company_name' => $signal->company_name,
                        'sector_id' => $signal->sector_id,
                        'sector_name' => $signal->sector_name,
                        'is_shariah' => (bool)$signal->is_shariah,
                        'strategies' => [
                            'explosive' => ['signals' => [], 'signal_date' => null],
                            'swing' => ['signals' => [], 'signal_date' => null],
                            'positional' => ['signals' => [], 'signal_date' => null],
                        ],
                    ];
                }

                $signalState = trim($signal->signal_state);
                if (isset(self::SIGNAL_STATE_MAP[$signalState])) {
                    $decodedMetadata = [];
                    if (!empty($signal->metadata)) {
                        $decodedMetadata = json_decode($signal->metadata, true);
                        if (!is_array($decodedMetadata)) {
                            $decodedMetadata = [];
                        }
                    }

                    $signalData = [
                        'state' => $signalState,
                        'value' => self::SIGNAL_STATE_MAP[$signalState],
                        'actual_value' => !empty($signal->signal_value) ? (float)$signal->signal_value : null,
                        'metadata' => $decodedMetadata,
                    ];

                    $stockMap[$key]['strategies'][$signal->strategy]['signals'][$signal->signal_name] = $signalData;
                    $stockMap[$key]['strategies'][$signal->strategy]['signal_date'] = $signal->signal_date;
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

            $signalValues = [];
            $availableSignals = array_keys($signals);

            foreach ($availableSignals as $signalName) {
                $state = $signals[$signalName]['state'] ?? null;
                if ($state && isset(self::SIGNAL_STATE_MAP[$state])) {
                    $signalValues[] = self::SIGNAL_STATE_MAP[$state];
                }
            }

            $signalsAvailable = count($signalValues);

            if ($signalsAvailable > 0) {
                $average = array_sum($signalValues) / $signalsAvailable;
                $score = (($average - 1) / 4) * 100;
            } else {
                $score = 0;
            }

            $signalDetails = [];
            foreach ($availableSignals as $signalName) {
                $state = $signals[$signalName]['state'] ?? null;
                if ($state && isset(self::SIGNAL_STATE_MAP[$state])) {
                    $signalDetails[$signalName] = [
                        'state' => $state,
                        'value' => self::SIGNAL_STATE_MAP[$state],
                    ];

                    if (isset($signals[$signalName]['actual_value'])) {
                        $signalDetails[$signalName]['actual_value'] = $signals[$signalName]['actual_value'];
                    }
                    if (isset($signals[$signalName]['metadata'])) {
                        $signalDetails[$signalName]['metadata'] = $signals[$signalName]['metadata'];
                    }
                }
            }

            $strategies[$strategy] = [
                'score' => round($score, 2),
                'signals_available' => $signalsAvailable,
                'signals' => $signalDetails,
            ];

            // Preserve signal_date if it exists
            if (isset($data['signal_date'])) {
                $strategies[$strategy]['signal_date'] = $data['signal_date'];
            }
        }

        return $strategies;
    }
}
