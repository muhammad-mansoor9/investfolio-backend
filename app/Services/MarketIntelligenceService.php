<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class MarketIntelligenceService
{
    private const CACHE_TTL = 1800; // 30 minutes (daily data updates)
    private const INSTITUTIONAL_INVESTOR_TYPES = [
        'FOREIGN CORPORATES',
        'MUTUAL FUNDS',
        'INSURANCE COMPANIES'
    ];

    public function getDashboardData(string $date): array
    {
        $cacheKey = "market_intelligence:dashboard:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($date) {
            $sectors = $this->calculateSectorRankings($date);
            $kpis = $this->calculateKPIs($sectors, $date);

            return [
                'date' => $date,
                'kpis' => $kpis,
                'sectors' => $sectors,
            ];
        });
    }

    public function getSectorDetail(string $sectorName, string $date): array
    {
        $cacheKey = "market_intelligence:sector:{$sectorName}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorName, $date) {
            $stocks = $this->calculateStockAccumulation($sectorName, $date);
            $flowHistory = $this->getSectorFlowHistory($sectorName, $date, 20);

            return [
                'sector_name' => $sectorName,
                'stocks' => $stocks,
                'flow_history_20d' => $flowHistory,
            ];
        });
    }

    public function getAccumulationLeaders(string $date, ?string $sectorName = null, ?string $state = null): array
    {
        $cacheKey = "market_intelligence:leaders:{$date}:{$sectorName}:{$state}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($date, $sectorName, $state) {
            $stocks = $this->getAllStockAccumulation($date);

            if ($sectorName) {
                $stocks = $stocks->filter(fn($s) => $s['sector_name'] === $sectorName);
            }

            if ($state) {
                $stocks = $stocks->filter(fn($s) => $s['state'] === $state);
            }

            return $stocks->sortByDesc('accumulation_score')->values()->toArray();
        });
    }

    public function getRotationHistory(string $date, int $days = 30): array
    {
        $cacheKey = "market_intelligence:rotation:{$date}:{$days}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($date, $days) {
            $endDate = \Carbon\Carbon::parse($date);
            $startDate = $endDate->copy()->subDays($days);

            $snapshots = [];
            $current = $startDate->copy();

            while ($current->lte($endDate)) {
                $dateStr = $current->format('Y-m-d');
                $sectors = $this->calculateSectorRankings($dateStr);
                $breadth = $this->calculateBreadth($sectors);

                $snapshots[] = [
                    'date' => $dateStr,
                    'sector_rankings' => $sectors,
                    'breadth_percentage' => $breadth,
                    'market_health' => $this->assessMarketHealth($sectors, $breadth),
                ];

                $current->addDay();
            }

            return $snapshots;
        });
    }

    public function getStockDetail(string $symbol, string $date): array
    {
        $cacheKey = "market_intelligence:stock_detail:{$symbol}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($symbol, $date) {
            $stock = DB::table('stocks')->where('symbol', $symbol)->first(['id', 'sector_id']);
            if (!$stock) {
                return ['symbol' => $symbol, 'error' => 'Stock not found'];
            }

            $flowHistory = $this->getStockFlowHistory($symbol, $date, 20);
            $priceData = $this->getStockPriceData($symbol, $date, 20);

            if (isset($flowHistory['error'])) {
                return $flowHistory;
            }

            $accumulationScore = $this->calculateStockAccumulationScore($flowHistory['daily_flows']);
            $state = $this->classifyState($accumulationScore);
            $pattern = $this->detectAccumulationPattern($flowHistory['daily_flows'], $priceData);

            return [
                'symbol' => $symbol,
                'accumulation_score' => $accumulationScore,
                'state' => $state,
                'pattern' => $pattern, // NEW: quiet_accumulation, distribution_in_disguise, etc.
                'flow_history' => $flowHistory['daily_flows'],
                'price_data' => $priceData,
            ];
        });
    }

    public function getStockFlowHistory(string $symbol, string $date, int $days = 60): array
    {
        $cacheKey = "market_intelligence:stock_history:{$symbol}:{$date}:{$days}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($symbol, $date, $days) {
            $endDate = Carbon::parse($date);
            $startDate = $endDate->copy()->subDays($days);
            $startStr = $startDate->format('Y-m-d');
            $endStr = $endDate->format('Y-m-d');

            $stock = DB::table('stocks')->where('symbol', $symbol)->first(['id', 'sector_id']);
            if (!$stock) {
                return ['symbol' => $symbol, 'error' => 'Stock not found'];
            }

            // Get sector name from the sector_id
            $sector = DB::table('sectors')->where('id', $stock->sector_id)->first(['name']);
            if (!$sector) {
                return ['symbol' => $symbol, 'error' => 'Sector not found'];
            }

            // Query FIPI/LIPI data for this sector
            $fipiData = DB::table('fipi_lipi_trading_data')
                ->select('investor_type', 'buy_value', 'sell_value', 'trade_date')
                ->where('sector_name', $sector->name)
                ->whereIn('investor_type', self::INSTITUTIONAL_INVESTOR_TYPES)
                ->whereBetween('trade_date', [$startStr, $endStr])
                ->orderBy('trade_date', 'asc')
                ->get();

            $uinData = DB::table('uin_settlement_data')
                ->where('symbol', $symbol)
                ->whereBetween('settlement_date', [$startStr, $endStr])
                ->orderBy('settlement_date', 'asc')
                ->get(['settlement_date', 'uin_percentage_value']);

            $daily = [];
            foreach ($fipiData as $record) {
                if (!isset($daily[$record->trade_date])) {
                    $daily[$record->trade_date] = [
                        'date' => $record->trade_date,
                        'foreign_corporates' => 0,
                        'mutual_funds' => 0,
                        'insurance' => 0,
                        'net_flow' => 0,
                        'uin_settlement_percent' => null,
                    ];
                }

                $flow = ($record->buy_value ?? 0) - ($record->sell_value ?? 0);

                switch ($record->investor_type) {
                    case 'FOREIGN CORPORATES':
                        $daily[$record->trade_date]['foreign_corporates'] += $flow;
                        break;
                    case 'MUTUAL FUNDS':
                        $daily[$record->trade_date]['mutual_funds'] += $flow;
                        break;
                    case 'INSURANCE COMPANIES':
                        $daily[$record->trade_date]['insurance'] += $flow;
                        break;
                }

                $daily[$record->trade_date]['net_flow'] += $flow;
            }

            foreach ($uinData as $uin) {
                if (isset($daily[$uin->settlement_date])) {
                    $daily[$uin->settlement_date]['uin_settlement_percent'] = $uin->uin_percentage_value;
                }
            }

            return [
                'symbol' => $symbol,
                'daily_flows' => array_values($daily),
            ];
        });
    }

    private function calculateSectorRankings(string $date): Collection
    {
        $fipiData = DB::table('fipi_lipi_trading_data')
            ->select('sector_name', 'investor_type', 'buy_value', 'sell_value')
            ->whereIn('investor_type', self::INSTITUTIONAL_INVESTOR_TYPES)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit(10000)
            ->get();

        $rolling20d = $this->calculateRollingFlows($date, 20, $fipiData);

        $sectors = collect();
        foreach ($rolling20d as $sectorName => $data) {
            $score = $this->calculateAccumulationScore(
                netFlow: $data['net_flow'],
                positiveDays: $data['positive_days'],
                totalDays: $data['total_days'],
                flowAcceleration: $data['flow_acceleration'],
                sectorBreadth: 0.5 // Placeholder
            );

            $state = $this->classifyState($score);
            $breadth = $this->calculateSectorBreadth($sectorName, $date);

            $sectors->push([
                'sector_name' => $sectorName,
                'net_flow_20d' => $data['net_flow'],
                'flow_trend' => $data['trend'],
                'breadth_percentage' => $breadth,
                'accumulation_score' => $score,
                'state' => $state,
                'positive_flow_days' => $data['positive_days'],
            ]);
        }

        return $sectors->sortByDesc('accumulation_score')->values();
    }

    private function calculateStockAccumulation(string $sectorName, string $date): Collection
    {
        $stocks = DB::table('stocks')
            ->where('sector_name', $sectorName)
            ->orWhereIn('sector_id', DB::table('sectors')
                ->where('name', $sectorName)
                ->pluck('id'))
            ->pluck('symbol')
            ->toArray();

        $results = collect();

        foreach ($stocks as $symbol) {
            $flowData = $this->getStockFlowHistory($symbol, $date, 20);
            if (isset($flowData['error'])) {
                continue;
            }

            $lastRecord = collect($flowData['daily_flows'])->last();
            if (!$lastRecord) {
                continue;
            }

            $positiveDays = collect($flowData['daily_flows'])->filter(fn($r) => $r['net_flow'] > 0)->count();
            $totalDays = count($flowData['daily_flows']);
            $netFlow = array_sum(array_column($flowData['daily_flows'], 'net_flow'));

            $score = $this->calculateAccumulationScore(
                netFlow: $netFlow,
                positiveDays: $positiveDays,
                totalDays: $totalDays,
                flowAcceleration: 0.1,
                sectorBreadth: 0.5
            );

            $results->push([
                'symbol' => $symbol,
                'sector_name' => $sectorName,
                'accumulation_score' => $score,
                'net_flow_20d' => $netFlow,
                'positive_flow_days' => $positiveDays,
                'state' => $this->classifyState($score),
                'uin_settlement_percent' => $lastRecord['uin_settlement_percent'] ?? 0,
            ]);
        }

        return $results->sortByDesc('accumulation_score')->values();
    }

    private function getAllStockAccumulation(string $date): Collection
    {
        $sectors = DB::table('sectors')->pluck('name')->toArray();
        $allStocks = collect();

        foreach ($sectors as $sector) {
            $stocks = $this->calculateStockAccumulation($sector, $date);
            $allStocks = $allStocks->merge($stocks);
        }

        return $allStocks;
    }

    private function calculateRollingFlows(string $date, int $days, Collection $fipiData): array
    {
        $endDate = Carbon::parse($date);
        $startDate = $endDate->copy()->subDays($days);
        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $grouped = $fipiData
            ->filter(fn($r) => $r->trade_date >= $startStr && $r->trade_date <= $endStr)
            ->groupBy('sector_name');

        $result = [];

        foreach ($grouped as $sectorName => $records) {
            $netFlow = $records->sum(fn($r) => ($r->buy_value ?? 0) - ($r->sell_value ?? 0));
            $byDay = $records->groupBy('trade_date')->map(fn($day) =>
                $day->sum(fn($r) => ($r->buy_value ?? 0) - ($r->sell_value ?? 0))
            );

            $positiveDays = $byDay->filter(fn($flow) => $flow > 0)->count();
            $totalDays = $byDay->count();

            $flowAcceleration = $this->calculateFlowAcceleration($byDay);

            $result[$sectorName] = [
                'net_flow' => $netFlow,
                'positive_days' => $positiveDays,
                'total_days' => $totalDays,
                'flow_acceleration' => $flowAcceleration,
                'trend' => $netFlow > 0 ? 'improving' : ($netFlow < 0 ? 'weakening' : 'stable'),
            ];
        }

        return $result;
    }

    private function calculateAccumulationScore(
        float $netFlow,
        int $positiveDays,
        int $totalDays,
        float $flowAcceleration,
        float $sectorBreadth
    ): float {
        // 30% - Net Flow Signal (normalize to ±30 points)
        // Assuming institutional flow range is -10B to +10B, map to 0-30
        $flowScore = max(0, min(30, 15 + ($netFlow / 1_000_000_000) * 1.5));

        // 20% - Consecutive Positive Days (0-20 points)
        $dayScore = ($totalDays > 0) ? ($positiveDays / $totalDays) * 20 : 0;

        // 15% - Flow Acceleration (0-15 points, clamped)
        $accelScore = max(0, min(15, 7.5 + $flowAcceleration * 50));

        // 20% - Buyer Concentration (placeholder, needs UIN data for accurate calc)
        // For now, assume moderate concentration
        $concentrationScore = 10;

        // 15% - Sector Breadth (0-15 points)
        $breadthScore = ($sectorBreadth / 100) * 15;

        // Total: 30 + 20 + 15 + 20 + 15 = 100
        $total = max(0, min(100, $flowScore + $dayScore + $accelScore + $concentrationScore + $breadthScore));

        return round($total, 1);
    }

    private function classifyState(float $score): string
    {
        return match (true) {
            $score >= 80 => 'strong_accumulation',
            $score >= 60 => 'accumulation',
            $score >= 40 => 'neutral',
            $score >= 20 => 'distribution',
            default => 'strong_distribution',
        };
    }

    private function calculateFlowAcceleration(Collection $dailyFlows): float
    {
        $count = $dailyFlows->count();
        if ($count < 5) {
            return 0; // Not enough data to calculate meaningful acceleration
        }

        $flows = $dailyFlows->values();
        $recent = $flows->slice(-5)->avg();
        $older = $count > 10 ? $flows->slice(0, -5)->avg() : $flows->slice(0, 5)->avg();

        if ($older == 0) {
            return $recent > 0 ? 0.5 : 0; // Avoid division by zero
        }

        return ($recent - $older) / abs($older);
    }

    private function calculateSectorBreadth(string $sectorName, string $date): float
    {
        // Count total active stocks in sector
        $totalStocks = DB::table('stocks')
            ->join('sectors', 'stocks.sector_id', '=', 'sectors.id')
            ->where('sectors.name', $sectorName)
            ->where('stocks.is_active', true)
            ->count();

        if ($totalStocks == 0) {
            return 0;
        }

        // Count distinct stocks with positive institutional flow on given date
        $positiveFlowStocks = DB::table('fipi_lipi_trading_data')
            ->select(DB::raw('COUNT(DISTINCT sector_name) as sector_count'))
            ->whereIn('investor_type', self::INSTITUTIONAL_INVESTOR_TYPES)
            ->where('sector_name', $sectorName)
            ->where('trade_date', '=', $date)
            ->where(DB::raw('(buy_value - sell_value)'), '>', 0)
            ->value('sector_count') ?? 0;

        // Since fipi_lipi_trading_data is by sector, we approximate by checking if sector had positive flow
        // A more accurate approach would require stock-level FIPI data
        $hasSectorFlow = $positiveFlowStocks > 0;

        return $hasSectorFlow ? 75.0 : 25.0; // Placeholder: actual breadth needs stock-level data
    }

    private function getSectorFlowHistory(string $sectorName, string $date, int $days): array
    {
        $endDate = \Carbon\Carbon::parse($date);
        $startDate = $endDate->copy()->subDays($days);

        $flows = DB::table('fipi_lipi_trading_data')
            ->select('trade_date', DB::raw('SUM(buy_value - sell_value) as net_flow'))
            ->whereIn('investor_type', self::INSTITUTIONAL_INVESTOR_TYPES)
            ->where('sector_name', $sectorName)
            ->whereBetween('trade_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->groupBy('trade_date')
            ->orderBy('trade_date', 'asc')
            ->get()
            ->map(fn($r) => ['date' => $r->trade_date, 'net_flow' => $r->net_flow])
            ->toArray();

        return $flows;
    }

    private function calculateKPIs(Collection $sectors, string $date): array
    {
        $positiveSectors = $sectors->filter(fn($s) => $s['net_flow_20d'] > 0)->count();
        $avgFlow = $sectors->avg('net_flow_20d');
        $leadingSector = $sectors->first();
        $avgBreadth = $sectors->avg('breadth_percentage');

        return [
            'positive_sectors' => $positiveSectors,
            'avg_institutional_flow' => round($avgFlow, 0),
            'leading_sector' => $leadingSector['sector_name'] ?? null,
            'market_breadth_percentage' => round($avgBreadth, 1),
        ];
    }

    private function calculateBreadth(Collection $sectors): float
    {
        if ($sectors->isEmpty()) {
            return 0;
        }

        $positive = $sectors->filter(fn($s) =>
            in_array($s['state'], ['strong_accumulation', 'accumulation'])
        )->count();

        return ($positive / $sectors->count()) * 100;
    }

    private function assessMarketHealth(Collection $sectors, float $breadth): string
    {
        return match (true) {
            $breadth >= 70 => 'strong',
            $breadth >= 50 => 'good',
            $breadth >= 30 => 'neutral',
            default => 'weak',
        };
    }

    // ===== PHASE 2: QUIET ACCUMULATION DETECTION =====

    private function getStockPriceData(string $symbol, string $date, int $days = 20): array
    {
        $endDate = Carbon::parse($date);
        $startDate = $endDate->copy()->subDays($days);
        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $stock = DB::table('stocks')->where('symbol', $symbol)->first(['id']);
        if (!$stock) {
            return [];
        }

        $prices = DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->whereBetween('date', [$startStr, $endStr])
            ->orderBy('date', 'asc')
            ->get(['date', 'open', 'close', 'high', 'low', 'volume'])
            ->toArray();

        if (empty($prices)) {
            return [];
        }

        // Calculate metrics
        $closes = array_column($prices, 'close');
        $highs = array_column($prices, 'high');
        $lows = array_column($prices, 'low');
        $volumes = array_column($prices, 'volume');

        // Volatility: (High - Low) / Close average
        $volatility = 0;
        $totalRange = 0;
        foreach ($prices as $p) {
            $totalRange += (($p->high - $p->low) / $p->close) * 100;
        }
        $volatility = $totalRange / count($prices);

        // Volume ratio
        $avgVolume = array_sum($volumes) / count($volumes);
        $lastVolume = end($volumes);
        $volumeRatio = $avgVolume > 0 ? $lastVolume / $avgVolume : 1;

        // Price range (high - low as % of close)
        $priceRange = ((max($highs) - min($lows)) / end($closes)) * 100;

        // Current position in range (0 = at low, 100 = at high)
        $currentClose = end($closes);
        $periodHigh = max($highs);
        $periodLow = min($lows);
        $positionInRange = $periodHigh == $periodLow ? 50 : (($currentClose - $periodLow) / ($periodHigh - $periodLow)) * 100;

        return [
            'dates' => array_column($prices, 'date'),
            'closes' => $closes,
            'volatility_20d' => round($volatility, 2), // % movement per day on average
            'price_range_20d' => round($priceRange, 2), // Total range as % of current price
            'position_in_range' => round($positionInRange, 1), // Where price is in its range
            'volume_ratio' => round($volumeRatio, 2), // Current volume / average
            'avg_volume' => (int)$avgVolume,
            'current_volume' => (int)$lastVolume,
        ];
    }

    private function calculateStockAccumulationScore(array $dailyFlows): float
    {
        if (empty($dailyFlows)) {
            return 0;
        }

        $collection = collect($dailyFlows);
        $netFlows = $collection->pluck('net_flow');

        $totalFlow = $netFlows->sum();
        $positiveDays = $netFlows->filter(fn($f) => $f > 0)->count();
        $totalDays = $collection->count();
        $flowAcceleration = $this->calculateFlowAcceleration($collection);

        return $this->calculateAccumulationScore(
            netFlow: $totalFlow,
            positiveDays: $positiveDays,
            totalDays: $totalDays,
            flowAcceleration: $flowAcceleration,
            sectorBreadth: 50 // Default 50% for individual stock
        );
    }

    private function detectAccumulationPattern(array $dailyFlows, array $priceData): ?string
    {
        if (empty($dailyFlows) || empty($priceData)) {
            return null;
        }

        $collection = collect($dailyFlows);
        $netFlows = $collection->pluck('net_flow');
        $totalFlow = $netFlows->sum();
        $positiveDays = $netFlows->filter(fn($f) => $f > 0)->count();
        $totalDays = $collection->count();
        $avgSettlement = $collection->pluck('uin_settlement_percent')->filter()->avg() ?? 0;

        $volatility = $priceData['volatility_20d'] ?? 0;
        $volumeRatio = $priceData['volume_ratio'] ?? 1;
        $positionInRange = $priceData['position_in_range'] ?? 50;

        // QUIET ACCUMULATION (Wyckoff Spring)
        if (
            $totalFlow > 0 &&                           // Buying
            $positiveDays >= 14 &&                      // Consistent (70%+)
            $avgSettlement >= 80 &&                     // High settlement
            $volatility < 3.0 &&                        // TIGHT RANGE
            $volumeRatio < 1.0 &&                       // VOLUME DECLINING
            $positionInRange > 30 && $positionInRange < 70  // Price not extreme
        ) {
            return 'quiet_accumulation';
        }

        // MARKUP PHASE (Breakout from consolidation)
        if (
            $totalFlow > 0 &&
            $volatility > 4.0 &&                        // Volume expanding
            $volumeRatio > 1.2 &&                       // Volume spiking
            $positionInRange > 60                       // Price near top of range
        ) {
            return 'markup_phase';
        }

        // DISTRIBUTION IN DISGUISE (Retail rally while institutions sell)
        if (
            $totalFlow < 0 &&                           // Net selling
            $avgSettlement < 50 &&                      // Low settlement (speculative)
            $volatility > 5.0 &&                        // High volatility
            $volumeRatio > 1.5 &&                       // Volume spiking
            $positionInRange > 70                       // Price near highs
        ) {
            return 'distribution_in_disguise';
        }

        // HIDDEN DISTRIBUTION (Quietly exiting)
        if (
            $totalFlow < 0 &&
            $positiveDays <= 8 &&                       // Few positive days
            $volatility < 2.5 &&                        // Low volatility
            $volumeRatio < 0.9 &&                       // Volume declining
            $positionInRange > 50                       // Price not collapsed yet
        ) {
            return 'hidden_distribution';
        }

        return null;
    }
}
