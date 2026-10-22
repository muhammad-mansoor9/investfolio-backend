<?php

namespace App\Services\DataIntegrity;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checks that every trading day has stock prices, daily indicators, FIPI/LIPI flows and UIN settlement.
 * A trading day is any date the exchange produced stock or index prices, so holidays and the occasional
 * weekend session need no calendar of their own.
 */
class DailyDataIntegrityService
{
    public const DATASETS = ['prices', 'indicators', 'fipiTrading', 'fipiMarket', 'uin'];

    private const CACHE_TTL = 900;
    private const RECENT_GAPS_LIMIT = 12;
    private const PRICE_SHORTFALL_RATIO = 0.9;
    private const MIN_DAYS_FOR_UNCOVERED = 3;

    private const DATASET_DATES = [
        'prices' => "SELECT date::text AS d FROM stock_prices GROUP BY date",
        'indicators' => "SELECT date::text AS d FROM stock_indicators WHERE timeframe = 'daily' GROUP BY date",
        'fipiTrading' => "SELECT trade_date::text AS d FROM fipi_lipi_trading_data GROUP BY trade_date",
        'fipiMarket' => "SELECT trade_date::text AS d FROM fipi_lipi_market_data GROUP BY trade_date",
        'uin' => "SELECT settlement_date::text AS d FROM uin_settlement_data GROUP BY settlement_date",
    ];

    // Per-stock datasets: every stock priced on a day should also have a row here for that day.
    private const PER_STOCK = [
        'indicators' => ['table' => 'stock_indicators', 'date' => 'date', 'filter' => "AND m.timeframe = 'daily'"],
        'uin' => ['table' => 'uin_settlement_data', 'date' => 'settlement_date', 'filter' => ''],
    ];

    public function getMonth(string $month): array
    {
        return Cache::remember("data_integrity:daily:{$month}", self::CACHE_TTL, function () use ($month) {
            $start = Carbon::createFromFormat('Y-m-d', "{$month}-01", 'Asia/Karachi')->startOfDay();
            $end = $start->copy()->endOfMonth()->startOfDay()->min(Carbon::now('Asia/Karachi')->startOfDay());
            $from = $start->toDateString();
            $to = $end->toDateString();

            $overview = $this->overview();
            $latestTradingDate = $overview['latestTradingDate'];
            $tradingDates = $this->tradingDates($from, $to);
            $counts = $this->countsByDate($from, $to);
            $priceBaseline = $this->median(array_values($counts['prices']));

            $missingByDay = [];
            $uncovered = [];
            foreach (array_keys(self::PER_STOCK) as $dataset) {
                [$missingByDay[$dataset], $uncovered[$dataset]] = $this->perStockGaps($dataset, $from, $to, $counts[$dataset]);
            }

            $today = Carbon::now('Asia/Karachi')->toDateString();
            $days = [];
            for ($date = $start->copy(); $date <= $end; $date->addDay()) {
                $key = $date->toDateString();
                if (isset($tradingDates[$key])) {
                    $days[] = $this->tradingDay($key, $counts, $missingByDay, $overview['datasets'], $priceBaseline, $key === $latestTradingDate);
                } elseif ($key !== $today) {
                    $days[] = $this->nonTradingDay($date);
                }
            }

            return [
                'month' => $month,
                'latestTradingDate' => $latestTradingDate,
                'summary' => $this->summarize($days),
                'uncovered' => $uncovered,
                'days' => array_reverse($days),
                'datasets' => $overview['datasets'],
            ];
        });
    }

    private function tradingDates(string $from, string $to): array
    {
        $rows = DB::select(
            "SELECT date::text AS d FROM stock_prices WHERE date BETWEEN ? AND ? GROUP BY date
             UNION
             SELECT date::text FROM index_prices WHERE date BETWEEN ? AND ? GROUP BY date",
            [$from, $to, $from, $to]
        );

        return array_fill_keys(array_column($rows, 'd'), true);
    }

    private function countsByDate(string $from, string $to): array
    {
        $pairs = fn (string $sql) => array_map('intval', array_column(DB::select($sql, [$from, $to]), 'c', 'd'));

        return [
            'prices' => $pairs("SELECT date::text AS d, COUNT(*) AS c FROM stock_prices WHERE date BETWEEN ? AND ? GROUP BY date"),
            'indicators' => $pairs("SELECT date::text AS d, COUNT(*) AS c FROM stock_indicators WHERE timeframe = 'daily' AND date BETWEEN ? AND ? GROUP BY date"),
            'fipiTrading' => $pairs("SELECT trade_date::text AS d, COUNT(*) AS c FROM fipi_lipi_trading_data WHERE trade_date BETWEEN ? AND ? GROUP BY trade_date"),
            'fipiMarket' => $pairs("SELECT trade_date::text AS d, COUNT(*) AS c FROM fipi_lipi_market_data WHERE trade_date BETWEEN ? AND ? GROUP BY trade_date"),
            'uin' => $pairs("SELECT settlement_date::text AS d, COUNT(*) AS c FROM uin_settlement_data WHERE settlement_date BETWEEN ? AND ? GROUP BY settlement_date"),
        ];
    }

    /**
     * Stocks priced on a day the dataset was loaded but missing their own row. Stocks the feed never carried
     * all month are split out as "uncovered" so one permanently absent symbol does not flag every day.
     *
     * @return array{0: array<string, string[]>, 1: string[]}
     */
    private function perStockGaps(string $dataset, string $from, string $to, array $datasetCounts): array
    {
        $loadedDates = array_keys(array_filter($datasetCounts));
        if (empty($loadedDates)) {
            return [[], []];
        }

        ['table' => $table, 'date' => $dateColumn, 'filter' => $filter] = self::PER_STOCK[$dataset];
        $loadedDatesLiteral = '{' . implode(',', $loadedDates) . '}';

        $missing = DB::select(
            "SELECT sp.date::text AS d, s.symbol FROM stock_prices sp
             JOIN stocks s ON s.id = sp.stock_id
             WHERE sp.date BETWEEN ? AND ? AND sp.date = ANY(?::date[])
               AND NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.stock_id = sp.stock_id AND m.{$dateColumn} = sp.date {$filter})
             ORDER BY s.symbol",
            [$from, $to, $loadedDatesLiteral]
        );

        $missingDaysBySymbol = array_count_values(array_column($missing, 'symbol'));
        $pricedDaysBySymbol = empty($missingDaysBySymbol) ? [] : array_map('intval', array_column(DB::select(
            "SELECT s.symbol, COUNT(*) AS c FROM stock_prices sp
             JOIN stocks s ON s.id = sp.stock_id
             WHERE sp.date BETWEEN ? AND ? AND sp.date = ANY(?::date[]) AND s.symbol = ANY(?::text[])
             GROUP BY s.symbol",
            [$from, $to, $loadedDatesLiteral, '{' . implode(',', array_map(fn ($s) => '"' . $s . '"', array_keys($missingDaysBySymbol))) . '}']
        ), 'c', 'symbol'));

        $uncovered = array_keys(array_filter(
            $missingDaysBySymbol,
            fn ($days, $symbol) => $days >= self::MIN_DAYS_FOR_UNCOVERED && $days === ($pricedDaysBySymbol[$symbol] ?? 0),
            ARRAY_FILTER_USE_BOTH
        ));

        $byDay = [];
        foreach ($missing as $row) {
            if (!in_array($row->symbol, $uncovered, true)) {
                $byDay[$row->d][] = $row->symbol;
            }
        }

        return [$byDay, $uncovered];
    }

    private function tradingDay(
        string $date,
        array $counts,
        array $missingByDay,
        array $overview,
        float $priceBaseline,
        bool $isLatest
    ): array {
        $datasets = [];
        foreach (self::DATASETS as $dataset) {
            $count = $counts[$dataset][$date] ?? 0;
            $missingSymbols = $missingByDay[$dataset][$date] ?? [];
            $firstDate = $overview[$dataset]['firstDate'];

            $status = match (true) {
                $firstDate === null || $date < $firstDate => 'untracked',
                $count === 0 => $isLatest ? 'pending' : 'missing',
                $dataset === 'prices' && $count < $priceBaseline * self::PRICE_SHORTFALL_RATIO => 'partial',
                !empty($missingSymbols) => 'partial',
                default => 'ok',
            };

            $datasets[$dataset] = ['status' => $status, 'count' => $count, 'missingSymbols' => $missingSymbols];
        }

        $statuses = array_column($datasets, 'status');
        $status = match (true) {
            in_array('missing', $statuses, true), in_array('partial', $statuses, true) => 'issues',
            in_array('pending', $statuses, true) => 'pending',
            default => 'complete',
        };

        return [
            'date' => $date,
            'dayOfWeek' => Carbon::parse($date)->format('l'),
            'status' => $status,
            'datasets' => $datasets,
        ];
    }

    private function nonTradingDay(Carbon $date): array
    {
        return [
            'date' => $date->toDateString(),
            'dayOfWeek' => $date->format('l'),
            'status' => $date->isWeekend() ? 'weekend' : 'closed',
            'datasets' => null,
        ];
    }

    private function summarize(array $days): array
    {
        $summary = [
            'tradingDays' => 0,
            'completeDays' => 0,
            'issueDays' => 0,
            'pendingDays' => 0,
            'closedWeekdays' => 0,
            'datasets' => array_fill_keys(self::DATASETS, ['missingDays' => 0, 'partialDays' => 0, 'stockGaps' => 0]),
        ];

        foreach ($days as $day) {
            if ($day['datasets'] === null) {
                if ($day['status'] === 'closed') {
                    $summary['closedWeekdays']++;
                }
                continue;
            }

            $summary['tradingDays']++;
            $summary[match ($day['status']) {
                'complete' => 'completeDays',
                'pending' => 'pendingDays',
                default => 'issueDays',
            }]++;

            foreach ($day['datasets'] as $dataset => $cell) {
                if ($cell['status'] === 'missing') {
                    $summary['datasets'][$dataset]['missingDays']++;
                } elseif ($cell['status'] === 'partial') {
                    $summary['datasets'][$dataset]['partialDays']++;
                }
                $summary['datasets'][$dataset]['stockGaps'] += count($cell['missingSymbols']);
            }
        }

        return $summary;
    }

    /** Whole-history view per dataset: how fresh it is and which trading days it has never covered since it started. */
    private function overview(): array
    {
        return Cache::remember('data_integrity:daily:overview', self::CACHE_TTL, function () {
            $datesByDataset = array_map(
                fn (string $sql) => array_column(DB::select($sql), 'd'),
                self::DATASET_DATES
            );
            $indexDates = array_column(DB::select("SELECT date::text AS d FROM index_prices GROUP BY date"), 'd');

            $calendar = array_unique(array_merge($datesByDataset['prices'], $indexDates));
            rsort($calendar);
            $latestTradingDate = $calendar[0] ?? null;

            $datasets = [];
            foreach ($datesByDataset as $dataset => $dates) {
                if (empty($dates)) {
                    $datasets[$dataset] = ['firstDate' => null, 'latestDate' => null, 'upToDate' => false, 'missingDays' => 0, 'recentMissing' => []];
                    continue;
                }

                $first = min($dates);
                $latest = max($dates);
                $present = array_flip($dates);
                // The latest trading day is still being loaded, so it is reported as pending rather than missing.
                $missing = array_values(array_filter(
                    $calendar,
                    fn ($d) => $d >= $first && $d < $latestTradingDate && !isset($present[$d])
                ));

                $datasets[$dataset] = [
                    'firstDate' => $first,
                    'latestDate' => $latest,
                    'upToDate' => $latest >= $latestTradingDate,
                    'missingDays' => count($missing),
                    'recentMissing' => array_slice($missing, 0, self::RECENT_GAPS_LIMIT),
                ];
            }

            return ['latestTradingDate' => $latestTradingDate, 'datasets' => $datasets];
        });
    }

    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
