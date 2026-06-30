<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TechnicalAnalysisController extends Controller
{
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

            return $this->successResponse([
                'date'            => $date,
                'total_results'   => $data->count(),
                'filters_applied' => count($filters),
                'data'            => $data,
            ], 'Technical analysis data retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to retrieve technical analysis data', $e);
        }
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
        sec.id                                                              AS sector_id,
        sec.name                                                            AS sector_name,
        sp.close                                                            AS price,
        sp.change,
        sp.volume,
        -- stored so crossover subqueries can reference the resolved date
        si_1d.date                                                          AS indicator_date_1d,
        si_1w.date                                                          AS indicator_date_1w,
        si_1m.date                                                          AS indicator_date_1m,
        -- EMA 1D
        (si_1d.data->'ema'->>'6')::numeric                                 AS ema_6_1d,
        (si_1d.data->'ema'->>'10')::numeric                                AS ema_10_1d,
        (si_1d.data->'ema'->>'22')::numeric                                AS ema_22_1d,
        (si_1d.data->'ema'->>'55')::numeric                                AS ema_55_1d,
        (si_1d.data->'ema'->>'100')::numeric                               AS ema_100_1d,
        (si_1d.data->'ema'->>'200')::numeric                               AS ema_200_1d,
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
        -- EMA 1W
        (si_1w.data->'ema'->>'6')::numeric                                 AS ema_6_1w,
        (si_1w.data->'ema'->>'10')::numeric                                AS ema_10_1w,
        (si_1w.data->'ema'->>'22')::numeric                                AS ema_22_1w,
        (si_1w.data->'ema'->>'55')::numeric                                AS ema_55_1w,
        (si_1w.data->'ema'->>'100')::numeric                               AS ema_100_1w,
        (si_1w.data->'ema'->>'200')::numeric                               AS ema_200_1w,
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
        -- EMA 1M
        (si_1m.data->'ema'->>'6')::numeric                                 AS ema_6_1m,
        (si_1m.data->'ema'->>'10')::numeric                                AS ema_10_1m,
        (si_1m.data->'ema'->>'22')::numeric                                AS ema_22_1m,
        (si_1m.data->'ema'->>'55')::numeric                                AS ema_55_1m,
        (si_1m.data->'ema'->>'100')::numeric                               AS ema_100_1m,
        (si_1m.data->'ema'->>'200')::numeric                               AS ema_200_1m,
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
    LEFT JOIN sectors sec ON s.sector_id = sec.id
    INNER JOIN stock_prices sp
        ON  sp.stock_id = s.id
        AND sp.date = (
            SELECT MAX(date) FROM stock_prices
            WHERE stock_id = s.id AND date <= :sd_sp
        )
    LEFT JOIN stock_indicators si_1d
        ON  si_1d.stock_id  = s.id
        AND si_1d.timeframe = '1D'
        AND si_1d.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = '1D' AND date <= :sd_1d
        )
    LEFT JOIN stock_indicators si_1w
        ON  si_1w.stock_id  = s.id
        AND si_1w.timeframe = '1W'
        AND si_1w.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = '1W' AND date <= :sd_1w
        )
    LEFT JOIN stock_indicators si_1m
        ON  si_1m.stock_id  = s.id
        AND si_1m.timeframe = '1M'
        AND si_1m.date = (
            SELECT MAX(date) FROM stock_indicators
            WHERE stock_id = s.id AND timeframe = '1M' AND date <= :sd_1m
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
            $tf        = strtolower($filter['timeframe'] ?? '1d');  // '1D' → '1d' for column names

            if (!in_array(strtoupper($tf), self::ALLOWED_TIMEFRAMES, true)) continue;
            if (!in_array($indicator, self::ALLOWED_INDICATORS, true)) continue;

            // CTE column suffix matches the lowercase alias (e.g. 'ema_22_1d')
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

                    $tfStr = strtoupper($tf);
                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfStr}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfStr}'
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

                    $tfStr = strtoupper($tf);
                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfStr}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfStr}'
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
                        $tfStr = strtoupper($tf);
                        $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfStr}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfStr}'
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

                    $tfStr = strtoupper($tf);
                    $conditions[] = "{$curr}
    AND EXISTS (
        SELECT 1 FROM stock_indicators xp
        WHERE xp.stock_id  = bd.stock_id
          AND xp.timeframe = '{$tfStr}'
          AND xp.date = (
              SELECT MAX(d.date) FROM stock_indicators d
              WHERE d.stock_id  = bd.stock_id
                AND d.timeframe = '{$tfStr}'
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
        $f = fn($v) => $v !== null ? (float) $v : null;

        return [
            'stock_id'    => $row->stock_id,
            'symbol'      => $row->symbol,
            'description' => $row->description,
            'sector_id'   => $row->sector_id,
            'sector_name' => $row->sector_name,
            'price'       => $f($row->price),
            'change'      => $f($row->change),
            'volume'      => $row->volume !== null ? (int) $row->volume : null,
            // EMA 1D
            'ema_6_1D'   => $f($row->ema_6_1d),   'ema_10_1D'  => $f($row->ema_10_1d),
            'ema_22_1D'  => $f($row->ema_22_1d),   'ema_55_1D'  => $f($row->ema_55_1d),
            'ema_100_1D' => $f($row->ema_100_1d),  'ema_200_1D' => $f($row->ema_200_1d),
            // EMA 1W
            'ema_6_1W'   => $f($row->ema_6_1w),   'ema_10_1W'  => $f($row->ema_10_1w),
            'ema_22_1W'  => $f($row->ema_22_1w),   'ema_55_1W'  => $f($row->ema_55_1w),
            'ema_100_1W' => $f($row->ema_100_1w),  'ema_200_1W' => $f($row->ema_200_1w),
            // EMA 1M
            'ema_6_1M'   => $f($row->ema_6_1m),   'ema_10_1M'  => $f($row->ema_10_1m),
            'ema_22_1M'  => $f($row->ema_22_1m),   'ema_55_1M'  => $f($row->ema_55_1m),
            'ema_100_1M' => $f($row->ema_100_1m),  'ema_200_1M' => $f($row->ema_200_1m),
            // RSI
            'rsi_value_1D'  => $f($row->rsi_value_1d),  'rsi_signal_1D'  => $f($row->rsi_signal_1d),
            'rsi_value_1W'  => $f($row->rsi_value_1w),  'rsi_signal_1W'  => $f($row->rsi_signal_1w),
            'rsi_value_1M'  => $f($row->rsi_value_1m),  'rsi_signal_1M'  => $f($row->rsi_signal_1m),
            // MACD
            'macd_line_1D'      => $f($row->macd_line_1d),
            'macd_signal_1D'    => $f($row->macd_signal_1d),
            'macd_histogram_1D' => $f($row->macd_histogram_1d),
            'macd_line_1W'      => $f($row->macd_line_1w),
            'macd_signal_1W'    => $f($row->macd_signal_1w),
            'macd_histogram_1W' => $f($row->macd_histogram_1w),
            'macd_line_1M'      => $f($row->macd_line_1m),
            'macd_signal_1M'    => $f($row->macd_signal_1m),
            'macd_histogram_1M' => $f($row->macd_histogram_1m),
            // Klinger
            'klinger_value_1D'  => $f($row->klinger_value_1d),
            'klinger_signal_1D' => $f($row->klinger_signal_1d),
            'klinger_value_1W'  => $f($row->klinger_value_1w),
            'klinger_signal_1W' => $f($row->klinger_signal_1w),
            'klinger_value_1M'  => $f($row->klinger_value_1m),
            'klinger_signal_1M' => $f($row->klinger_signal_1m),
        ];
    }
}
