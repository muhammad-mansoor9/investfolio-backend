<?php

namespace App\Services\DataIntegrity;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checks that each active stock's quarterly series is unbroken from its own first quarter up to the
 * latest quarter whose filing deadline has passed. Series may start at different quarters per stock.
 */
class FinancialDataIntegrityService
{
    public const SOURCE_FINANCIAL_DATA = 'financial_data';
    public const SOURCE_FINANCIAL_RESULTS = 'financial_results';

    private const CACHE_TTL = 900;

    // PSX filing deadlines after quarter end: Q1/Q3 30 days, half-year 60, annual 120.
    // The grace period absorbs scraping lag before a quarter is reported as overdue.
    private const DEADLINE_QUARTER = 30;
    private const DEADLINE_HALF_YEAR = 60;
    private const DEADLINE_ANNUAL = 120;
    private const GRACE_DAYS = 15;

    private const THIN_PERIOD_RATIO = 0.5;
    private const MIN_PERIODS_FOR_THIN_CHECK = 4;

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const QUARTER_END_LABELS = [1 => 'Mar', 2 => 'Jun', 3 => 'Sep', 4 => 'Dec'];

    public function getReport(string $source): array
    {
        return Cache::remember("data_integrity:financial:v2:{$source}", self::CACHE_TTL, function () use ($source) {
            $today = Carbon::now('Asia/Karachi')->startOfDay();
            $latestEndedQuarter = $this->latestEndedQuarter($today);
            $periodsByStock = $source === self::SOURCE_FINANCIAL_DATA
                ? $this->financialDataPeriods()
                : $this->financialResultsPeriods();

            $stocks = [];
            foreach ($this->activeStocks() as $stock) {
                $key = $source === self::SOURCE_FINANCIAL_DATA ? $stock->symbol : $stock->id;
                $stocks[] = $this->analyze(
                    $stock,
                    $periodsByStock[$key] ?? [],
                    $source === self::SOURCE_FINANCIAL_DATA,
                    $today,
                    $latestEndedQuarter
                );
            }

            return [
                'source' => $source,
                'latestEndedQuarter' => $this->label($latestEndedQuarter),
                'summary' => $this->summarize($stocks),
                'stocks' => $stocks,
            ];
        });
    }

    private function activeStocks(): array
    {
        return DB::table('stocks as s')
            ->leftJoin('sectors as sec', 'sec.id', '=', 's.sector_id')
            ->where('s.is_active', true)
            ->orderBy('s.symbol')
            ->get(['s.id', 's.symbol', 's.year_ending', 'sec.name as sector'])
            ->all();
    }

    /** @return array<string, array<string, int>> symbol => [raw period label => row count] */
    private function financialDataPeriods(): array
    {
        $grouped = [];
        foreach (DB::select(
            "SELECT symbol, header, COUNT(*) AS c FROM financial_data WHERE type = 'QUARTERLY' GROUP BY symbol, header"
        ) as $row) {
            $grouped[$row->symbol][$row->header] = (int) $row->c;
        }

        return $grouped;
    }

    /** @return array<string, array<string, int>> stock id => [raw quarter label => row count] */
    private function financialResultsPeriods(): array
    {
        $grouped = [];
        foreach (DB::select(
            "SELECT stock_id, quarter, COUNT(*) AS c FROM financial_results WHERE quarter IS NOT NULL GROUP BY stock_id, quarter"
        ) as $row) {
            $grouped[$row->stock_id][$row->quarter] = (int) $row->c;
        }

        return $grouped;
    }

    private function analyze(object $stock, array $rawPeriods, bool $isFinancialData, Carbon $today, int $latestEndedQuarter): array
    {
        $rowsByIndex = [];
        $anomalies = [];

        foreach ($rawPeriods as $raw => $rows) {
            $index = $this->parse((string) $raw);

            if ($index === null) {
                if (!$isFinancialData || !$this->isNonQuarterHeader((string) $raw)) {
                    $anomalies[] = "Unrecognised period label \"{$raw}\"";
                }
                continue;
            }

            $canonical = $this->label($index);
            if ($index > $latestEndedQuarter) {
                $anomalies[] = "Future period {$canonical}";
                continue;
            }
            if ($raw !== $canonical) {
                $anomalies[] = isset($rawPeriods[$canonical])
                    ? "\"{$raw}\" duplicates {$canonical}"
                    : "Non-standard label \"{$raw}\" (expected \"{$canonical}\")";
            }

            $rowsByIndex[$index] = ($rowsByIndex[$index] ?? 0) + $rows;
        }

        $expectedLatest = $this->expectedLatestQuarter($stock->year_ending, $today, $latestEndedQuarter);
        $row = [
            'stockId' => $stock->id,
            'symbol' => $stock->symbol,
            'sector' => $stock->sector,
            'firstPeriod' => null,
            'latestPeriod' => null,
            'expectedLatest' => $this->label($expectedLatest),
            'available' => count($rowsByIndex),
            'gaps' => [],
            'overdue' => [],
            'thin' => [],
            'anomalies' => $anomalies,
            'series' => [],
            'status' => 'NO_DATA',
        ];

        if (empty($rowsByIndex)) {
            return $row;
        }

        ksort($rowsByIndex);
        $first = array_key_first($rowsByIndex);
        $latest = array_key_last($rowsByIndex);
        $thinThreshold = $isFinancialData && count($rowsByIndex) >= self::MIN_PERIODS_FOR_THIN_CHECK
            ? $this->median(array_values($rowsByIndex)) * self::THIN_PERIOD_RATIO
            : 0;

        for ($i = $first; $i <= $latestEndedQuarter; $i++) {
            $label = $this->label($i);
            $rows = $rowsByIndex[$i] ?? 0;

            $status = match (true) {
                $rows > 0 && $rows < $thinThreshold => 'thin',
                $rows > 0 => 'available',
                $i < $latest => 'gap',
                $i <= $expectedLatest => 'overdue',
                default => 'pending',
            };

            $bucket = ['thin' => 'thin', 'gap' => 'gaps', 'overdue' => 'overdue'][$status] ?? null;
            if ($bucket !== null) {
                $row[$bucket][] = $label;
            }

            $row['series'][] = ['period' => $label, 'status' => $status, 'rows' => $rows];
        }

        $row['firstPeriod'] = $this->label($first);
        $row['latestPeriod'] = $this->label($latest);
        $row['status'] = match (true) {
            !empty($row['gaps']) => 'GAPS',
            !empty($row['overdue']) => 'OVERDUE',
            !empty($row['thin']) => 'THIN',
            default => 'COMPLETE',
        };

        return $row;
    }

    private function summarize(array $stocks): array
    {
        $summary = [
            'checkedStocks' => count($stocks),
            'complete' => 0,
            'gaps' => 0,
            'overdue' => 0,
            'thin' => 0,
            'noData' => 0,
            'withAnomalies' => 0,
            'gapPeriods' => 0,
            'overduePeriods' => 0,
        ];

        foreach ($stocks as $stock) {
            $summary[match ($stock['status']) {
                'COMPLETE' => 'complete',
                'GAPS' => 'gaps',
                'OVERDUE' => 'overdue',
                'THIN' => 'thin',
                default => 'noData',
            }]++;
            $summary['gapPeriods'] += count($stock['gaps']);
            $summary['overduePeriods'] += count($stock['overdue']);
            if (!empty($stock['anomalies'])) {
                $summary['withAnomalies']++;
            }
        }

        return $summary;
    }

    /** Quarters are indexed as year * 4 + (quarter - 1) so consecutive quarters differ by exactly one. */
    private function parse(string $raw): ?int
    {
        if (!preg_match("/^([A-Za-z]{3,9})\.?\s*['\x{2019}]?\s*(\d{2}|\d{4})$/u", trim($raw), $m)) {
            return null;
        }

        $month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
        if ($month === null || $month % 3 !== 0) {
            return null;
        }

        $year = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];

        return $year * 4 + intdiv($month, 3) - 1;
    }

    private function label(int $index): string
    {
        return sprintf("%s '%02d", self::QUARTER_END_LABELS[$index % 4 + 1], intdiv($index, 4) % 100);
    }

    private function isNonQuarterHeader(string $raw): bool
    {
        return (bool) preg_match('/^(FY\d{4}|NTM|TTM|LTM)/i', trim($raw));
    }

    private function latestEndedQuarter(Carbon $today): int
    {
        return $today->year * 4 + intdiv($today->month - 1, 3) - 1;
    }

    private function expectedLatestQuarter(?string $yearEnding, Carbon $today, int $latestEndedQuarter): int
    {
        $fiscalYearEndMonth = $yearEnding === null
            ? null
            : (self::MONTHS[strtolower(substr(trim($yearEnding), 0, 3))] ?? null);

        for ($index = $latestEndedQuarter; ; $index--) {
            $quarterEndMonth = ($index % 4 + 1) * 3;
            $deadline = match (true) {
                $fiscalYearEndMonth === null, $quarterEndMonth === $fiscalYearEndMonth => self::DEADLINE_ANNUAL,
                ($fiscalYearEndMonth + 6 - 1) % 12 + 1 === $quarterEndMonth => self::DEADLINE_HALF_YEAR,
                default => self::DEADLINE_QUARTER,
            };

            $quarterEnd = Carbon::create(intdiv($index, 4), $quarterEndMonth, 1, 0, 0, 0, 'Asia/Karachi')->endOfMonth()->startOfDay();
            if ($quarterEnd->addDays($deadline + self::GRACE_DAYS)->lte($today)) {
                return $index;
            }
        }
    }

    private function median(array $values): float
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
