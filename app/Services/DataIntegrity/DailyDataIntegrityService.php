<?php

namespace App\Services\DataIntegrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DailyDataIntegrityService
{
    private const CACHE_TTL = 3600; // 1 hour for monthly aggregations

    /**
     * Get monthly integrity overview
     *
     * @param string $month Format: YYYY-MM
     * @return array
     */
    public function getMonthlyIntegrity(string $month): array
    {
        $cacheKey = "data_integrity:daily:{$month}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($month) {
            // Parse month
            $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
            $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();

            // Get active stocks count for display
            $activeStocksCount = $this->getActiveStocksCount();

            // Fetch all dataset aggregations
            $pricesByDate = $this->getStockPricesByDate($startDate, $endDate);
            $indicatorsByDate = $this->getIndicatorsByDate($startDate, $endDate);
            $signalsByDate = $this->getSignalsByDate($startDate, $endDate);
            $fipiTradingByDate = $this->getFipiTradingByDate($startDate, $endDate);
            $fipiMarketByDate = $this->getFipiMarketByDate($startDate, $endDate);
            $uinByDate = $this->getUinSettlementByDate($startDate, $endDate);

            // Build daily status rows
            $currentDate = Carbon::parse($startDate);
            $endDateCarbon = Carbon::parse($endDate);
            $today = Carbon::now()->startOfDay();
            $days = [];

            while ($currentDate <= $endDateCarbon) {
                // Skip future dates
                if ($currentDate > $today) {
                    $currentDate->addDay();
                    continue;
                }

                $dateStr = $currentDate->toDateString();
                $isWeekend = $currentDate->isWeekend();

                // Assemble dataset availability for this date
                $datasets = DataIntegrityStatus::getEmptyDatasetStructure();
                $datasets[DataIntegrityStatus::DATASET_PRICES] = $pricesByDate[$dateStr] ?? ['available' => false, 'count' => 0];
                $datasets[DataIntegrityStatus::DATASET_INDICATORS] = $indicatorsByDate[$dateStr] ?? ['available' => false, 'count' => 0];
                $datasets[DataIntegrityStatus::DATASET_SIGNALS] = $signalsByDate[$dateStr] ?? ['available' => false, 'count' => 0];
                $datasets[DataIntegrityStatus::DATASET_FIPI_TRADING] = $fipiTradingByDate[$dateStr] ?? ['available' => false, 'count' => 0];
                $datasets[DataIntegrityStatus::DATASET_FIPI_MARKET] = $fipiMarketByDate[$dateStr] ?? ['available' => false, 'count' => 0];
                $datasets[DataIntegrityStatus::DATASET_UIN_SETTLEMENT] = $uinByDate[$dateStr] ?? ['available' => false, 'count' => 0];

                // Determine status - validate prices and indicators match
                $status = $this->determineDateStatusWithValidation(
                    $datasets,
                    $activeStocksCount,
                    $isWeekend
                );

                // Only identify issues for problematic statuses, not for expected closures
                $issues = in_array($status, [
                    DataIntegrityStatus::STATUS_WEEKEND,
                    DataIntegrityStatus::STATUS_HOLIDAY,
                ])
                    ? []
                    : $this->identifyIssues($datasets, $status);

                $days[] = [
                    'date' => $dateStr,
                    'dayOfWeek' => $currentDate->format('l'),
                    'activeStocks' => $activeStocksCount,
                    'status' => $status,
                    'datasets' => $datasets,
                    'issues' => $issues,
                ];

                $currentDate->addDay();
            }

            // Calculate summary
            $summary = $this->calculateMonthlySummary($days);

            return [
                'month' => $month,
                'summary' => $summary,
                'days' => $days,
            ];
        });
    }

    /**
     * Get detailed daily integrity report
     *
     * @param string $date Format: YYYY-MM-DD
     * @return array
     */
    public function getDailyIntegrity(string $date): array
    {
        $pricesDetail = $this->getStockPricesDetail($date);
        $indicatorsDetail = $this->getIndicatorsDetail($date);
        $signalsDetail = $this->getSignalsDetail($date);
        $fipiTradingDetail = $this->getFipiTradingDetail($date);
        $fipiMarketDetail = $this->getFipiMarketDetail($date);
        $uinDetail = $this->getUinSettlementDetail($date);

        $datasets = DataIntegrityStatus::getEmptyDatasetStructure();
        $datasets[DataIntegrityStatus::DATASET_PRICES] = [
            'available' => $pricesDetail,
            'count' => $pricesDetail ? 1 : 0,
        ];
        $datasets[DataIntegrityStatus::DATASET_INDICATORS] = [
            'available' => $indicatorsDetail,
            'count' => $indicatorsDetail ? 1 : 0,
        ];
        $datasets[DataIntegrityStatus::DATASET_SIGNALS] = [
            'available' => $signalsDetail,
            'count' => $signalsDetail ? 1 : 0,
        ];
        $datasets[DataIntegrityStatus::DATASET_FIPI_TRADING] = [
            'available' => $fipiTradingDetail,
            'count' => $fipiTradingDetail ? 1 : 0,
        ];
        $datasets[DataIntegrityStatus::DATASET_FIPI_MARKET] = [
            'available' => $fipiMarketDetail,
            'count' => $fipiMarketDetail ? 1 : 0,
        ];
        $datasets[DataIntegrityStatus::DATASET_UIN_SETTLEMENT] = [
            'available' => $uinDetail,
            'count' => $uinDetail ? 1 : 0,
        ];

        $dateCarbon = Carbon::parse($date);
        $isWeekend = $dateCarbon->isWeekend();
        $activeStocksCount = $this->getActiveStocksCount();

        $status = $this->determineDateStatusWithValidation(
            $datasets,
            $activeStocksCount,
            $isWeekend
        );

        $issues = in_array($status, [
            DataIntegrityStatus::STATUS_WEEKEND,
            DataIntegrityStatus::STATUS_HOLIDAY,
        ])
            ? []
            : $this->identifyIssues($datasets, $status);

        return [
            'date' => $date,
            'dayOfWeek' => $dateCarbon->format('l'),
            'status' => $status,
            'datasets' => $datasets,
            'issues' => $issues,
        ];
    }

    // ========== AGGREGATION QUERIES BY DATE ==========

    private function getActiveStocksCount(): int
    {
        return (int) DB::table('stocks')->where('is_active', true)->count();
    }

    private function getStockPricesByDate(string $startDate, string $endDate): array
    {
        $results = DB::table('stock_prices')
            ->selectRaw("DATE(date) as trading_date, COUNT(DISTINCT stock_id) as stock_count")
            ->whereBetween(DB::raw('DATE(date)'), [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(date)'))
            ->orderBy(DB::raw('DATE(date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->trading_date] = [
                'available' => true,
                'count' => (int) $row->stock_count,
            ];
        }
        return $output;
    }

    private function getIndicatorsByDate(string $startDate, string $endDate): array
    {
        // Only fetch daily timeframe indicators
        $results = DB::table('stock_indicators')
            ->selectRaw("DATE(date) as data_date, COUNT(DISTINCT stock_id) as stock_count")
            ->where('timeframe', 'daily')
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(date)'))
            ->orderBy(DB::raw('DATE(date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->data_date] = [
                'available' => true,
                'count' => (int) $row->stock_count,
            ];
        }
        return $output;
    }

    private function getSignalsByDate(string $startDate, string $endDate): array
    {
        $results = DB::table('stock_signals')
            ->selectRaw("DATE(signal_date) as signal_date, COUNT(DISTINCT stock_id) as stock_count")
            ->whereBetween('signal_date', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(signal_date)'))
            ->orderBy(DB::raw('DATE(signal_date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->signal_date] = [
                'available' => true,
                'count' => (int) $row->stock_count,
            ];
        }
        return $output;
    }

    private function getFipiTradingByDate(string $startDate, string $endDate): array
    {
        $results = DB::table('fipi_lipi_trading_data')
            ->selectRaw("DATE(trade_date) as trade_date, COUNT(*) as record_count")
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(trade_date)'))
            ->orderBy(DB::raw('DATE(trade_date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->trade_date] = [
                'available' => true,
                'count' => (int) $row->record_count,
            ];
        }
        return $output;
    }

    private function getFipiMarketByDate(string $startDate, string $endDate): array
    {
        $results = DB::table('fipi_lipi_market_data')
            ->selectRaw("DATE(trade_date) as trade_date, COUNT(*) as record_count")
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(trade_date)'))
            ->orderBy(DB::raw('DATE(trade_date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->trade_date] = [
                'available' => true,
                'count' => (int) $row->record_count,
            ];
        }
        return $output;
    }

    private function getUinSettlementByDate(string $startDate, string $endDate): array
    {
        $results = DB::table('uin_settlement_data')
            ->selectRaw("DATE(settlement_date) as settlement_date, COUNT(DISTINCT stock_id) as stock_count")
            ->whereBetween('settlement_date', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(settlement_date)'))
            ->orderBy(DB::raw('DATE(settlement_date)'))
            ->get();

        $output = [];
        foreach ($results as $row) {
            $output[$row->settlement_date] = [
                'available' => true,
                'count' => (int) $row->stock_count,
            ];
        }
        return $output;
    }

    // ========== DETAIL QUERIES FOR SINGLE DATE ==========

    private function getStockPricesDetail(string $date): bool
    {
        return DB::table('stock_prices')->whereDate('date', $date)->exists();
    }

    private function getIndicatorsDetail(string $date): bool
    {
        return DB::table('stock_indicators')->whereDate('date', $date)->exists();
    }

    private function getSignalsDetail(string $date): bool
    {
        return DB::table('stock_signals')->whereDate('signal_date', $date)->exists();
    }

    private function getFipiTradingDetail(string $date): bool
    {
        return DB::table('fipi_lipi_trading_data')->whereDate('trade_date', $date)->exists();
    }

    private function getFipiMarketDetail(string $date): bool
    {
        return DB::table('fipi_lipi_market_data')->whereDate('trade_date', $date)->exists();
    }

    private function getUinSettlementDetail(string $date): bool
    {
        return DB::table('uin_settlement_data')->whereDate('settlement_date', $date)->exists();
    }

    // ========== HELPER METHODS ==========

    private function calculateMonthlySummary(array $days): array
    {
        $healthyDays = 0;
        $incompleteDays = 0;
        $closedDays = 0;
        $issueCount = 0;
        $latestCompleteDate = null;

        foreach ($days as $day) {
            match ($day['status']) {
                DataIntegrityStatus::STATUS_COMPLETE => $healthyDays++,
                DataIntegrityStatus::STATUS_PARTIAL => $incompleteDays++,
                DataIntegrityStatus::STATUS_WEEKEND, DataIntegrityStatus::STATUS_HOLIDAY => $closedDays++,
                DataIntegrityStatus::STATUS_NO_DATA => $issueCount++,
                default => null,
            };

            if ($day['status'] === DataIntegrityStatus::STATUS_COMPLETE) {
                $latestCompleteDate = $day['date'];
            }

            if ($day['status'] === DataIntegrityStatus::STATUS_PARTIAL) {
                $issueCount++;
            }
        }

        return [
            'healthyDays' => $healthyDays,
            'incompleteDays' => $incompleteDays,
            'closedDays' => $closedDays,
            'issueCount' => $issueCount,
            'latestCompleteDate' => $latestCompleteDate,
        ];
    }

    private function determineDateStatusWithValidation(array $datasets, int $activeStocksCount, bool $isWeekend): string
    {
        // Weekend/closed market
        if ($isWeekend) {
            return DataIntegrityStatus::STATUS_WEEKEND;
        }

        $prices = $datasets[DataIntegrityStatus::DATASET_PRICES];
        $indicators = $datasets[DataIntegrityStatus::DATASET_INDICATORS];
        $signals = $datasets[DataIntegrityStatus::DATASET_SIGNALS];
        $fipiTrading = $datasets[DataIntegrityStatus::DATASET_FIPI_TRADING];
        $fipiMarket = $datasets[DataIntegrityStatus::DATASET_FIPI_MARKET];
        $uin = $datasets[DataIntegrityStatus::DATASET_UIN_SETTLEMENT];

        // Check if all 6 required datasets exist
        $allPresent = $prices['available'] && $indicators['available'] && $signals['available'] &&
                      $fipiTrading['available'] && $fipiMarket['available'] && $uin['available'];

        // Check if prices and indicators counts match
        $countsMatch = $prices['count'] === $indicators['count'];

        // COMPLETE: all datasets present AND prices == indicators
        if ($allPresent && $countsMatch) {
            return DataIntegrityStatus::STATUS_COMPLETE;
        }

        // PARTIAL: any dataset missing OR prices != indicators
        if ($prices['available'] || $indicators['available'] || $signals['available'] ||
            $fipiTrading['available'] || $fipiMarket['available'] || $uin['available']) {
            return DataIntegrityStatus::STATUS_PARTIAL;
        }

        // NO_DATA: no datasets on a trading day
        return DataIntegrityStatus::STATUS_NO_DATA;
    }

    private function identifyIssues(array $datasets, string $status): array
    {
        if ($status === DataIntegrityStatus::STATUS_COMPLETE) {
            return [];
        }

        $prices = $datasets[DataIntegrityStatus::DATASET_PRICES];
        $indicators = $datasets[DataIntegrityStatus::DATASET_INDICATORS];
        $signals = $datasets[DataIntegrityStatus::DATASET_SIGNALS];
        $fipiTrading = $datasets[DataIntegrityStatus::DATASET_FIPI_TRADING];
        $fipiMarket = $datasets[DataIntegrityStatus::DATASET_FIPI_MARKET];
        $uin = $datasets[DataIntegrityStatus::DATASET_UIN_SETTLEMENT];
        $issues = [];

        // Check for missing datasets
        if (!$prices['available']) $issues[] = "Stock prices missing";
        if (!$indicators['available']) $issues[] = "Indicators missing";
        if (!$signals['available']) $issues[] = "Signals missing";
        if (!$fipiTrading['available']) $issues[] = "FIPI trading missing";
        if (!$fipiMarket['available']) $issues[] = "FIPI market missing";
        if (!$uin['available']) $issues[] = "UIN settlement missing";

        // Check price/indicator mismatch
        if ($prices['available'] && $indicators['available'] && $prices['count'] !== $indicators['count']) {
            $issues[] = "Prices ({$prices['count']}) vs Indicators ({$indicators['count']}) mismatch";
        }

        return $issues;
    }
}
