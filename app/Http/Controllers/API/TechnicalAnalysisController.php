<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\StockIndicatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TechnicalAnalysisController extends Controller
{
    public function __construct(
        private StockIndicatorService $indicatorService,
    ) {}
    private const ALLOWED_EMA_PERIODS = [6, 10, 22, 55, 100, 200];
    private const ALLOWED_OPERATORS   = ['>', '<', '>=', '<='];
    private const ALLOWED_TIMEFRAMES  = ['1D', '1W', '1M'];
    private const ALLOWED_INDICATORS  = [
        'price_vs_ema',
        'ema_crossover',
        'rsi_value',
        'rsi_crossover',
        'macd_line_vs_signal',
        'macd_histogram',
        'klinger_value',
        'klinger_crossover',
    ];

    public function getData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date'                 => 'required|date_format:Y-m-d',
                'filters'              => 'nullable|array|max:20',
                'filters.*.indicator'  => 'required|in:price_vs_ema,ema_crossover,rsi_value,rsi_crossover,macd_line_vs_signal,macd_histogram,klinger_value,klinger_crossover',
                'filters.*.timeframe'  => 'required|in:1D,1W,1M',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $date    = $request->input('date');
            $filters = $request->input('filters', []);

            $built = $this->buildQuery($date, $filters);
            $rows  = DB::select($built['sql'], $built['bindings']);
            $data  = collect($rows)->map(fn($row) => $this->mapRow($row));

            $metadata = $this->getAvailableIndicatorMetadata($data);

            return $this->successResponse([
                'date'            => $date,
                'total_results'   => $data->count(),
                'filters_applied' => count($filters),
                'metadata'        => $metadata,
                'data'            => $data,
            ], 'Technical analysis data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve technical analysis data', $e);
        }
    }

    private function getAvailableIndicatorMetadata(object $data): array
    {
        // Analyze data to determine which EMA periods exist for each timeframe
        $emaPeriodsByTf = [
            '1D' => [],
            '1W' => [],
            '1M' => [],
        ];

        foreach ($data as $row) {
            foreach ($emaPeriodsByTf as $tf => &$periods) {
                // Check which EMA period fields have non-null values
                for ($p = 6; $p <= 200; $p = $p === 6 ? 10 : ($p === 10 ? 22 : ($p === 22 ? 55 : ($p === 55 ? 100 : ($p === 100 ? 200 : 999))))) {
                    $key = "ema_{$p}_" . strtoupper($tf);
                    if (isset($row->$key) && $row->$key !== null) {
                        if (!in_array($p, $periods)) {
                            $periods[] = $p;
                        }
                    }
                }
            }
        }

        // Sort periods
        foreach ($emaPeriodsByTf as &$periods) {
            sort($periods);
        }

        return [
            'available_indicators' => [
                'ema' => [
                    'name' => 'Exponential Moving Average',
                    'by_timeframe' => [
                        '1D' => array_merge(range(1, 22), [50, 55, 100, 200]),
                        '1W' => array_merge(range(1, 22), [50, 55, 100]),
                        '1M' => array_merge(range(1, 22), [50, 55]),
                    ],
                    'default_visible_per_tf' => [
                        '1D' => [6, 10, 22, 55, 100, 200],
                        '1W' => [6, 10, 22, 55, 100],
                        '1M' => [6, 10, 22, 55],
                    ],
                ],
                'rsi' => [
                    'name' => 'Relative Strength Index',
                    'fields' => ['value', 'signal'],
                    'default_visible' => ['value'],
                ],
                'macd' => [
                    'name' => 'MACD',
                    'fields' => ['line', 'signal', 'histogram'],
                    'default_visible' => ['line', 'histogram'],
                ],
                'klinger' => [
                    'name' => 'Klinger Volume Oscillator',
                    'fields' => ['value', 'signal'],
                    'default_visible' => ['value'],
                ],
            ],
            'timeframes' => ['1D', '1W', '1M'],
            'default_timeframes' => ['1D'],
        ];
    }

    private function buildQuery(string $date, array $filters): array
    {
        // Use distinct binding names so the same date value can be referenced multiple
        // times without hitting PDO's per-driver deduplication behaviour.
        $bindings = [
            'sd_sp' => $date,  // stock_prices latest date
            'sd_1d' => $date,  // 1D indicator latest date
            'sd_1w' => $date,  // 1W indicator latest date
            'sd_1m' => $date,  // 1M indicator latest date
        ];

        $baseCte = "
WITH base_data AS (
    SELECT
        s.id                                                                AS stock_id,
        s.symbol,
        s.description,
        s.sector_id,
        sp.close                                                            AS price,
        sp.change,
        sp.volume,
        -- stored so crossover subqueries can reference the resolved date
        si_1d.date                                                          AS indicator_date_1d,
        si_1w.date                                                          AS indicator_date_1w,
        si_1m.date                                                          AS indicator_date_1m,
        -- EMA 1D (extract all periods as JSON)
        si_1d.data->'ema'                                                  AS ema_data_1d,
        -- RSI 1D
        (si_1d.data->'rsi'->>'value')::numeric                             AS rsi_value_1d,
        (si_1d.data->'rsi'->>'ma')::numeric                                AS rsi_signal_1d,
        -- MACD 1D
        (si_1d.data->'macd'->>'line')::numeric                             AS macd_line_1d,
        (si_1d.data->'macd'->>'signal')::numeric                           AS macd_signal_1d,
        (si_1d.data->'macd'->>'histogram')::numeric                        AS macd_histogram_1d,
        -- Klinger 1D
        (si_1d.data->'klinger'->>'value')::numeric                         AS klinger_value_1d,
        (si_1d.data->'klinger'->>'signal')::numeric                        AS klinger_signal_1d,
        -- EMA 1W (extract all periods as JSON)
        si_1w.data->'ema'                                                  AS ema_data_1w,
        -- RSI 1W
        (si_1w.data->'rsi'->>'value')::numeric                             AS rsi_value_1w,
        (si_1w.data->'rsi'->>'ma')::numeric                                AS rsi_signal_1w,
        -- MACD 1W
        (si_1w.data->'macd'->>'line')::numeric                             AS macd_line_1w,
        (si_1w.data->'macd'->>'signal')::numeric                           AS macd_signal_1w,
        (si_1w.data->'macd'->>'histogram')::numeric                        AS macd_histogram_1w,
        -- Klinger 1W
        (si_1w.data->'klinger'->>'value')::numeric                         AS klinger_value_1w,
        (si_1w.data->'klinger'->>'signal')::numeric                        AS klinger_signal_1w,
        -- EMA 1M (extract all periods as JSON)
        si_1m.data->'ema'                                                  AS ema_data_1m,
        -- RSI 1M
        (si_1m.data->'rsi'->>'value')::numeric                             AS rsi_value_1m,
        (si_1m.data->'rsi'->>'ma')::numeric                                AS rsi_signal_1m,
        -- MACD 1M
        (si_1m.data->'macd'->>'line')::numeric                             AS macd_line_1m,
        (si_1m.data->'macd'->>'signal')::numeric                           AS macd_signal_1m,
        (si_1m.data->'macd'->>'histogram')::numeric                        AS macd_histogram_1m,
        -- Klinger 1M
        (si_1m.data->'klinger'->>'value')::numeric                         AS klinger_value_1m,
        (si_1m.data->'klinger'->>'signal')::numeric                        AS klinger_signal_1m
    FROM stocks s
    INNER JOIN stock_prices sp
        ON  sp.stock_id = s.id
        AND sp.date = (
            SELECT MAX(date) FROM stock_prices
            WHERE stock_id = s.id AND date <= :sd_sp
        )
    LEFT JOIN stock_indicators si_1d
        ON  si_1d.stock_id  = s.id
        AND si_1d.timeframe = 'daily'
        AND si_1d.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = 'daily' AND date <= :sd_1d
        )
    LEFT JOIN stock_indicators si_1w
        ON  si_1w.stock_id  = s.id
        AND si_1w.timeframe = 'weekly'
        AND si_1w.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = 'weekly' AND date <= :sd_1w
        )
    LEFT JOIN stock_indicators si_1m
        ON  si_1m.stock_id  = s.id
        AND si_1m.timeframe = 'monthly'
        AND si_1m.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = 'monthly' AND date <= :sd_1m
        )
    WHERE s.is_active = true
      AND s.market_cap > 0
)";

        $conditions = $this->buildFilterConditions($filters, $bindings);
        $whereSql   = empty($conditions) ? '' : "\nWHERE " . implode("\n  AND ", $conditions);
        $sql        = $baseCte . "\nSELECT * FROM base_data bd" . $whereSql . "\nORDER BY bd.symbol";

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    private function buildFilterConditions(array $filters, array &$bindings): array
    {
        $conditions = [];

        foreach ($filters as $i => $filter) {
            $indicator = $filter['indicator'] ?? '';
            $tfInput   = strtoupper($filter['timeframe'] ?? '1D');  // e.g., '1D'

            if (!in_array($tfInput, self::ALLOWED_TIMEFRAMES, true)) continue;
            if (!in_array($indicator, self::ALLOWED_INDICATORS, true)) continue;

            // Map '1D', '1W', '1M' to database timeframe values
            $tfMap = ['1D' => 'daily', '1W' => 'weekly', '1M' => 'monthly'];
            $tfDb  = $tfMap[$tfInput];
            $tf    = strtolower($tfInput);  // 'daily' → '1d' for column aliases in CTE

            // CTE column suffix matches the lowercase code (e.g. '1d' for daily)
            $sfx     = $tf;                          // e.g. '1d'
            $datecol = "indicator_date_{$sfx}";      // e.g. 'indicator_date_1d'

            switch ($indicator) {
                case 'price_vs_ema': {
                    $op  = $filter['operator'] ?? '>';
                    $ema = (int) ($filter['emaPeriod'] ?? 22);
                    if (!in_array($op, ['>', '<'], true)) break;
                    if (!in_array($ema, self::ALLOWED_EMA_PERIODS, true)) break;
                    $conditions[] = "bd.price {$op} bd.ema_{$ema}_{$sfx}";
                    break;
                }

                case 'ema_crossover': {
                    $fast = (int) ($filter['fastPeriod'] ?? 6);
                    $slow = (int) ($filter['slowPeriod'] ?? 22);
                    $dir  = $filter['direction'] ?? 'crossover';
                    if (!in_array($fast, self::ALLOWED_EMA_PERIODS, true)) break;
                    if (!in_array($slow, self::ALLOWED_EMA_PERIODS, true)) break;
                    if (!in_array($dir, ['crossover', 'crossunder'], true)) break;
                    if ($fast === $slow) break;

                    if ($dir === 'crossover') {
                        $curr = "bd.ema_{$fast}_{$sfx} > bd.ema_{$slow}_{$sfx}";
                        $prev = "(xp.data->'ema'->>'$fast')::numeric <= (xp.data->'ema'->>'$slow')::numeric";
                    } else {
                        $curr = "bd.ema_{$fast}_{$sfx} < bd.ema_{$slow}_{$sfx}";
                        $prev = "(xp.data->'ema'->>'$fast')::numeric >= (xp.data->'ema'->>'$slow')::numeric";
                    }

                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfDb}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfDb}'
                AND d.date < bd.{$datecol}
          )
          AND {$prev}
    )";
                    break;
                }

                case 'rsi_value': {
                    $op  = $filter['operator'] ?? '>';
                    $val = (float) ($filter['value'] ?? 50);
                    if (!in_array($op, self::ALLOWED_OPERATORS, true)) break;
                    $vk             = "f{$i}_v";
                    $bindings[$vk]  = $val;
                    $conditions[]   = "bd.rsi_value_{$sfx} {$op} :{$vk}";
                    break;
                }

                case 'rsi_crossover': {
                    $dir = $filter['direction'] ?? 'crossover';
                    if (!in_array($dir, ['crossover', 'crossunder'], true)) break;

                    if ($dir === 'crossover') {
                        $curr = "bd.rsi_value_{$sfx} > bd.rsi_signal_{$sfx}";
                        $prev = "(xp.data->'rsi'->>'value')::numeric <= (xp.data->'rsi'->>'ma')::numeric";
                    } else {
                        $curr = "bd.rsi_value_{$sfx} < bd.rsi_signal_{$sfx}";
                        $prev = "(xp.data->'rsi'->>'value')::numeric >= (xp.data->'rsi'->>'ma')::numeric";
                    }

                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfDb}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfDb}'
                AND d.date < bd.{$datecol}
          )
          AND {$prev}
    )";
                    break;
                }

                case 'macd_line_vs_signal': {
                    $op = $filter['operator'] ?? '>';
                    if (in_array($op, ['>', '<'], true)) {
                        $conditions[] = "bd.macd_line_{$sfx} {$op} bd.macd_signal_{$sfx}";
                    } elseif (in_array($op, ['crossover', 'crossunder'], true)) {
                        if ($op === 'crossover') {
                            $curr = "bd.macd_line_{$sfx} > bd.macd_signal_{$sfx}";
                            $prev = "(xp.data->'macd'->>'line')::numeric <= (xp.data->'macd'->>'signal')::numeric";
                        } else {
                            $curr = "bd.macd_line_{$sfx} < bd.macd_signal_{$sfx}";
                            $prev = "(xp.data->'macd'->>'line')::numeric >= (xp.data->'macd'->>'signal')::numeric";
                        }
                        $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfDb}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfDb}'
                AND d.date < bd.{$datecol}
          )
          AND {$prev}
    )";
                    }
                    break;
                }

                case 'macd_histogram': {
                    $op = $filter['operator'] ?? '>';
                    if (!in_array($op, ['>', '<'], true)) break;
                    $conditions[] = "bd.macd_histogram_{$sfx} {$op} 0";
                    break;
                }

                case 'klinger_value': {
                    $op  = $filter['operator'] ?? '>';
                    $val = (float) ($filter['value'] ?? 0);
                    if (!in_array($op, self::ALLOWED_OPERATORS, true)) break;
                    $vk             = "f{$i}_v";
                    $bindings[$vk]  = $val;
                    $conditions[]   = "bd.klinger_value_{$sfx} {$op} :{$vk}";
                    break;
                }

                case 'klinger_crossover': {
                    $dir = $filter['direction'] ?? 'crossover';
                    if (!in_array($dir, ['crossover', 'crossunder'], true)) break;

                    if ($dir === 'crossover') {
                        $curr = "bd.klinger_value_{$sfx} > bd.klinger_signal_{$sfx}";
                        $prev = "(xp.data->'klinger'->>'value')::numeric <= (xp.data->'klinger'->>'signal')::numeric";
                    } else {
                        $curr = "bd.klinger_value_{$sfx} < bd.klinger_signal_{$sfx}";
                        $prev = "(xp.data->'klinger'->>'value')::numeric >= (xp.data->'klinger'->>'signal')::numeric";
                    }

                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfDb}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfDb}'
                AND d.date < bd.{$datecol}
          )
          AND {$prev}
    )";
                    break;
                }
            }
        }

        return $conditions;
    }

    private function mapRow(object $row): array
    {
        $f = function($v) { return $v !== null ? (float) $v : null; };

        $result = [
            'stock_id'    => $row->stock_id,
            'symbol'      => $row->symbol,
            'description' => $row->description,
            'sector_id'   => $row->sector_id,
            'price'       => $f($row->price),
            'change'      => $f($row->change),
            'volume'      => $row->volume !== null ? (int) $row->volume : null,
        ];

        // Extract all EMA periods from JSON for each timeframe
        $result = array_merge($result, $this->extractEmaValues($row->ema_data_1d, '1D'));
        $result = array_merge($result, $this->extractEmaValues($row->ema_data_1w, '1W'));
        $result = array_merge($result, $this->extractEmaValues($row->ema_data_1m, '1M'));

        // RSI
        $result['rsi_value_1D'] = $f($row->rsi_value_1d);
        $result['rsi_signal_1D'] = $f($row->rsi_signal_1d);
        $result['rsi_value_1W'] = $f($row->rsi_value_1w);
        $result['rsi_signal_1W'] = $f($row->rsi_signal_1w);
        $result['rsi_value_1M'] = $f($row->rsi_value_1m);
        $result['rsi_signal_1M'] = $f($row->rsi_signal_1m);

        // MACD
        $result['macd_line_1D'] = $f($row->macd_line_1d);
        $result['macd_signal_1D'] = $f($row->macd_signal_1d);
        $result['macd_histogram_1D'] = $f($row->macd_histogram_1d);
        $result['macd_line_1W'] = $f($row->macd_line_1w);
        $result['macd_signal_1W'] = $f($row->macd_signal_1w);
        $result['macd_histogram_1W'] = $f($row->macd_histogram_1w);
        $result['macd_line_1M'] = $f($row->macd_line_1m);
        $result['macd_signal_1M'] = $f($row->macd_signal_1m);
        $result['macd_histogram_1M'] = $f($row->macd_histogram_1m);

        // Klinger
        $result['klinger_value_1D'] = $f($row->klinger_value_1d);
        $result['klinger_signal_1D'] = $f($row->klinger_signal_1d);
        $result['klinger_value_1W'] = $f($row->klinger_value_1w);
        $result['klinger_signal_1W'] = $f($row->klinger_signal_1w);
        $result['klinger_value_1M'] = $f($row->klinger_value_1m);
        $result['klinger_signal_1M'] = $f($row->klinger_signal_1m);

        return $result;
    }

    private function extractEmaValues($emaJson, string $tf): array
    {
        $result = [];
        if (is_null($emaJson)) {
            return $result;
        }

        $emaData = json_decode($emaJson, true);
        if (!is_array($emaData)) {
            return $result;
        }

        $f = function($v) { return $v !== null ? (float) $v : null; };

        foreach ($emaData as $period => $value) {
            $period = (int) $period;
            $key = "ema_{$period}_{$tf}";
            $result[$key] = $f($value);
        }

        return $result;
    }
}
