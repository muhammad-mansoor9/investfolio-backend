<?php

namespace App\Services\DataIntegrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class FinancialDataIntegrityService
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get financial integrity report (quarterly or annual)
     *
     * @param string $type Either 'quarterly' or 'annual'
     * @param string|null $searchSymbol Optional stock symbol filter
     * @param string|null $statusFilter Optional status filter ('all' or 'broken')
     * @return array
     */
    public function getFinancialIntegrity(
        string $type = 'quarterly',
        ?string $searchSymbol = null,
        ?string $statusFilter = null
    ): array {
        $cacheKey = 'data_integrity:financial:' . $type . ':' . ($searchSymbol ?: 'all') . ':' . ($statusFilter ?: 'all');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($type, $searchSymbol, $statusFilter) {
            // Fetch all distinct periods from financial_data table
            // Try both uppercase and lowercase variants
            $periodTypeOptions = $type === 'quarterly' ? ['QUARTERLY', 'quarterly', 'Q'] : ['ANNUAL', 'annual', 'A'];

            $query = DB::table('financial_data')
                ->whereIn('type', $periodTypeOptions)
                ->select('symbol', 'header')
                ->distinct()
                ->orderBy('symbol')
                ->orderBy('header');

            $records = $query->get();

            // Group by symbol and extract period info
            $periodsBySymbol = [];
            foreach ($records as $record) {
                if (!isset($periodsBySymbol[$record->symbol])) {
                    $periodsBySymbol[$record->symbol] = [];
                }
                $periodsBySymbol[$record->symbol][$record->header] = true;
            }

            // Get stocks to analyze (filtered by search)
            $stocks = $this->getStocksWithFinancialData($type, $searchSymbol);

            // Analyze each stock
            $analyzed = [];
            foreach ($stocks as $stock) {
                $periods = array_keys($periodsBySymbol[$stock->symbol] ?? []);
                $analysis = $this->analyzeStockFinancialPeriods($stock->symbol, $stock->id, $periods, $type);

                // Filter by status if requested
                if ($statusFilter === 'broken' && $analysis['status'] !== 'BROKEN_SERIES') {
                    continue;
                }

                $analyzed[] = $analysis;
            }

            // Calculate summary
            $summary = $this->calculateFinancialSummary($analyzed);

            return [
                'type' => $type,
                'summary' => $summary,
                'stocks' => $analyzed,
            ];
        });
    }

    /**
     * Get detailed period history for a stock
     *
     * @param string $stockId
     * @param string $type Either 'quarterly' or 'annual'
     * @return array
     */
    public function getStockPeriodHistory(string $stockId, string $type = 'quarterly'): array
    {
        $stock = DB::table('stocks')->where('id', $stockId)->first(['id', 'symbol']);

        if (!$stock) {
            return ['error' => 'Stock not found'];
        }

        $periodTypeOptions = $type === 'quarterly' ? ['QUARTERLY', 'quarterly', 'Q'] : ['ANNUAL', 'annual', 'A'];

        // Get distinct periods for this stock from financial_data
        $periods = DB::table('financial_data')
            ->where('symbol', $stock->symbol)
            ->whereIn('type', $periodTypeOptions)
            ->select('header')
            ->distinct()
            ->orderBy('header')
            ->pluck('header')
            ->toArray();

        return $this->buildFinancialPeriodMatrix($stock->symbol, $periods, $type);
    }

    // ========== HELPER METHODS ==========

    private function getStocksWithFinancialData(string $type, ?string $searchSymbol): array
    {
        $query = DB::table('stocks')->where('is_active', true);

        if ($searchSymbol) {
            $query->where('symbol', 'ILIKE', "%{$searchSymbol}%");
        }

        return $query->orderBy('symbol')->get(['id', 'symbol'])->toArray();
    }

    /**
     * Analyze stock financial periods from financial_data table
     */
    private function analyzeStockFinancialPeriods(string $symbol, string $stockId, array $periods, string $type): array
    {
        if (empty($periods)) {
            return [
                'symbol' => $symbol,
                'stockId' => $stockId,
                'type' => $type,
                'firstPeriod' => null,
                'latestPeriod' => null,
                'expectedPeriods' => 0,
                'availablePeriods' => 0,
                'missingPeriods' => 0,
                'missingList' => [],
                'status' => 'NO_DATA',
            ];
        }

        // Parse periods to extract year and month/quarter info
        $periodData = [];
        foreach ($periods as $period) {
            $parsed = $this->parsePeriodString($period, $type);
            if ($parsed) {
                $periodData[] = $parsed;
            }
        }

        if (empty($periodData)) {
            return [
                'symbol' => $symbol,
                'stockId' => $stockId,
                'type' => $type,
                'firstPeriod' => null,
                'latestPeriod' => null,
                'expectedPeriods' => 0,
                'availablePeriods' => 0,
                'missingPeriods' => 0,
                'missingList' => [],
                'status' => 'NO_DATA',
            ];
        }

        // Sort by year and quarter
        usort($periodData, function ($a, $b) {
            if ($a['year'] !== $b['year']) {
                return $a['year'] <=> $b['year'];
            }
            return ($a['quarter'] ?? 0) <=> ($b['quarter'] ?? 0);
        });

        // Build expected sequence
        $firstYear = $periodData[0]['year'];
        $latestYear = $periodData[count($periodData) - 1]['year'];
        $firstQuarter = $type === 'quarterly' ? ($periodData[0]['quarter'] ?? 1) : 1;
        $latestQuarter = $type === 'quarterly' ? ($periodData[count($periodData) - 1]['quarter'] ?? 4) : 1;

        // Generate expected periods
        $expectedPeriods = $this->generateExpectedPeriods($firstYear, $firstQuarter, $latestYear, $latestQuarter, $type);
        $availableSet = $this->getPeriodSet($periodData, $type);

        // Detect missing periods
        $missingPeriods = array_diff($expectedPeriods, $availableSet);
        sort($missingPeriods);

        // Check structural consistency (statements, tables, identifiers)
        $structuralIssues = $this->checkStructuralIntegrity($symbol, array_map(fn($p) => $p['name'], $periodData), $type);

        // Determine status: BROKEN if temporal or structural issues exist
        $status = empty($missingPeriods) && empty($structuralIssues) ? 'HEALTHY' : 'BROKEN_SERIES';

        // Format first/latest periods
        $firstPeriod = $this->formatPeriodLabel($firstYear, $firstQuarter, $type);
        $latestPeriod = $this->formatPeriodLabel($latestYear, $latestQuarter, $type);

        return [
            'symbol' => $symbol,
            'stockId' => $stockId,
            'type' => $type,
            'firstPeriod' => $firstPeriod,
            'latestPeriod' => $latestPeriod,
            'expectedPeriods' => count($expectedPeriods),
            'availablePeriods' => count($periodData),
            'missingPeriods' => count($missingPeriods),
            'missingList' => $missingPeriods,
            'status' => $status,
            'structuralIssuesCount' => count($structuralIssues),
            'hasStructuralIssues' => !empty($structuralIssues),
        ];
    }

    /**
     * Build period matrix for financial_data periods
     */
    private function buildFinancialPeriodMatrix(string $symbol, array $periodStrings, string $type): array
    {
        if (empty($periodStrings)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        // Parse all period strings
        $periods = [];
        foreach ($periodStrings as $periodStr) {
            $parsed = $this->parsePeriodString($periodStr, $type);
            if ($parsed) {
                $periods[] = $parsed;
            }
        }

        if (empty($periods)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        // Sort by year
        usort($periods, function ($a, $b) {
            return $a['year'] <=> $b['year'];
        });

        if ($type === 'quarterly') {
            return $this->buildQuarterlyMatrixFromPeriods($symbol, $periods);
        } else {
            return $this->buildAnnualMatrixFromPeriods($symbol, $periods);
        }
    }

    private function buildQuarterlyMatrixFromPeriods(string $symbol, array $periods): array
    {
        $byYear = [];
        $availablePeriods = [];

        foreach ($periods as $period) {
            $year = $period['year'];
            $quarter = $period['quarter'];

            if (!isset($byYear[$year])) {
                $byYear[$year] = [];
            }

            $byYear[$year][$quarter] = true;
            $availablePeriods["{$year}-Q{$quarter}"] = true;
        }

        if (empty($byYear)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        $minYear = min(array_keys($byYear));
        $maxYear = max(array_keys($byYear));
        $currentYear = (int) date('Y');
        $currentQuarter = (int) ceil(date('m') / 3);

        $result = ['symbol' => $symbol, 'years' => []];

        for ($year = $maxYear; $year >= $minYear; $year--) {
            $yearData = ['year' => $year, 'quarters' => []];

            for ($q = 1; $q <= 4; $q++) {
                $periodKey = "{$year}-Q{$q}";
                $available = isset($availablePeriods[$periodKey]);
                // Mark as FUTURE if it's after current quarter
                $isFuture = $year > $currentYear || ($year === $currentYear && $q > $currentQuarter);

                $yearData['quarters'][] = [
                    'quarter' => "Q{$q}",
                    'status' => $isFuture ? 'FUTURE' : ($available ? 'AVAILABLE' : 'MISSING'),
                ];
            }

            $result['years'][] = $yearData;
        }

        return $result;
    }

    private function buildAnnualMatrixFromPeriods(string $symbol, array $periods): array
    {
        $availableYears = [];
        foreach ($periods as $period) {
            $availableYears[$period['year']] = true;
        }

        if (empty($availableYears)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        $minYear = min(array_keys($availableYears));
        $maxYear = max(array_keys($availableYears));
        $currentYear = (int) date('Y');

        $result = ['symbol' => $symbol, 'years' => []];

        for ($year = $maxYear; $year >= $minYear; $year--) {
            // Mark as FUTURE if it's after current year
            $isFuture = $year > $currentYear;
            $available = isset($availableYears[$year]);

            $result['years'][] = [
                'year' => $year,
                'status' => $isFuture ? 'FUTURE' : ($available ? 'AVAILABLE' : 'MISSING'),
            ];
        }

        return $result;
    }

    /**
     * Calculate summary statistics
     */
    private function calculateFinancialSummary(array $stocks): array
    {
        $total = count($stocks);
        $healthy = 0;
        $broken = 0;
        $totalMissing = 0;

        foreach ($stocks as $stock) {
            if ($stock['status'] === 'HEALTHY') {
                $healthy++;
            } else {
                $broken++;
            }
            $totalMissing += $stock['missingPeriods'];
        }

        return [
            'checkedStocks' => $total,
            'completeSeries' => $healthy,
            'brokenSeries' => $broken,
            'missingPeriods' => $totalMissing,
        ];
    }

    /**
     * Check if financial statements have consistent structure across periods
     */
    private function checkStructuralIntegrity(string $symbol, array $periods, string $type): array
    {
        if (empty($periods)) {
            return [];
        }

        $periodTypeOptions = $type === 'quarterly' ? ['QUARTERLY', 'quarterly', 'Q'] : ['ANNUAL', 'annual', 'A'];

        // Batch-load all structural data in one query instead of N+1
        $data = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->whereIn('header', $periods)
            ->whereIn('type', $periodTypeOptions)
            ->select('header', 'statement', 'table_name', 'identifier')
            ->distinct()
            ->get();

        // Group by period and build structure map
        $structureByPeriod = [];
        foreach ($data as $row) {
            if (!isset($structureByPeriod[$row->header])) {
                $structureByPeriod[$row->header] = [];
            }
            if (!isset($structureByPeriod[$row->header][$row->statement])) {
                $structureByPeriod[$row->header][$row->statement] = [];
            }
            if (!isset($structureByPeriod[$row->header][$row->statement][$row->table_name])) {
                $structureByPeriod[$row->header][$row->statement][$row->table_name] = 0;
            }
            $structureByPeriod[$row->header][$row->statement][$row->table_name]++;
        }

        // Normalize all structures (sort for comparison)
        foreach ($structureByPeriod as &$period) {
            ksort($period);
            foreach ($period as &$statements) {
                ksort($statements);
            }
        }

        // Compare first period with all others
        $issues = [];
        if (!empty($structureByPeriod)) {
            $firstPeriod = reset($structureByPeriod);
            $firstKey = key($structureByPeriod);

            foreach ($structureByPeriod as $period => $structure) {
                if ($period === $firstKey) continue;

                // Check if structure matches
                if (json_encode($firstPeriod) !== json_encode($structure)) {
                    $issues[] = "Period {$period} has different structure than {$firstKey}";
                }
            }
        }

        return $issues;
    }

    // ========== UTILITY FUNCTIONS ==========

    private function parsePeriodString(string $periodStr, string $type): ?array
    {
        $monthMap = [
            'Jan' => 1, 'Feb' => 1, 'Mar' => 1,
            'Apr' => 2, 'May' => 2, 'Jun' => 2,
            'Jul' => 3, 'Aug' => 3, 'Sep' => 3,
            'Oct' => 4, 'Nov' => 4, 'Dec' => 4,
        ];

        // Trim whitespace
        $periodStr = trim($periodStr);

        // Try to parse different period formats
        // Format 1: "Jun '16" or "Jun-16" or "Jun-2016"
        if (preg_match('/^([A-Za-z]{3})\s+(.+)$/', $periodStr, $matches)) {
            $monthStr = $matches[1];
            $yearStr = trim($matches[2], '\'-');

            if (is_numeric($yearStr)) {
                $year = strlen($yearStr) === 2 ? (int)('20' . $yearStr) : (int)$yearStr;
                $quarter = $monthMap[$monthStr] ?? null;

                if ($quarter === null) {
                    return null;
                }

                return [
                    'name' => $periodStr,
                    'year' => $year,
                    'quarter' => $type === 'quarterly' ? $quarter : null,
                ];
            }
        }

        // Format 2: "YYYY-MM" or "YYYY/MM"
        if (preg_match('/^(\d{4})[-\/](\d{2})$/', $periodStr, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $quarter = $month <= 3 ? 1 : ($month <= 6 ? 2 : ($month <= 9 ? 3 : 4));

            return [
                'name' => $periodStr,
                'year' => $year,
                'quarter' => $type === 'quarterly' ? $quarter : null,
            ];
        }

        return null;
    }

    private function extractQuarter(string $periodName, string $type): ?int
    {
        if ($type !== 'quarterly') {
            return null;
        }

        // Period name format: something like "Mar-25", "Jun-25", etc.
        $monthMap = [
            'Jan' => 1, 'Feb' => 1, 'Mar' => 1,
            'Apr' => 2, 'May' => 2, 'Jun' => 2,
            'Jul' => 3, 'Aug' => 3, 'Sep' => 3,
            'Oct' => 4, 'Nov' => 4, 'Dec' => 4,
        ];

        $month = substr($periodName, 0, 3);
        return $monthMap[$month] ?? null;
    }

    private function getPeriodSet(array $periodData, string $type): array
    {
        $set = [];
        foreach ($periodData as $period) {
            $set[] = $this->formatPeriodKey($period['year'], $period['quarter'], $type);
        }
        return $set;
    }

    private function generateExpectedPeriods(int $startYear, ?int $startQuarter, int $endYear, ?int $endQuarter, string $type): array
    {
        $periods = [];

        if ($type === 'quarterly') {
            $startQuarter = $startQuarter ?? 1;
            $endQuarter = $endQuarter ?? 4;

            for ($year = $startYear; $year <= $endYear; $year++) {
                $qStart = ($year === $startYear) ? $startQuarter : 1;
                $qEnd = ($year === $endYear) ? $endQuarter : 4;

                for ($q = $qStart; $q <= $qEnd; $q++) {
                    $periods[] = $this->formatPeriodKey($year, $q, 'quarterly');
                }
            }
        } else {
            for ($year = $startYear; $year <= $endYear; $year++) {
                $periods[] = $this->formatPeriodKey($year, null, 'annual');
            }
        }

        return $periods;
    }

    private function formatPeriodKey(int $year, ?int $quarter, string $type): string
    {
        if ($type === 'quarterly') {
            return "{$year}-Q{$quarter}";
        }
        return "{$year}";
    }

    private function formatPeriodLabel(int $year, ?int $quarter, string $type): string
    {
        if ($type === 'quarterly') {
            return "{$year}-Q{$quarter}";
        }
        return "{$year}";
    }

    /**
     * Get financial results integrity report (quarterly only)
     *
     * @param string|null $searchSymbol Optional stock symbol filter
     * @param string|null $statusFilter Optional status filter ('all' or 'broken')
     * @return array
     */
    public function getFinancialResultsIntegrity(
        ?string $searchSymbol = null,
        ?string $statusFilter = null
    ): array {
        $cacheKey = 'data_integrity:financial_results:' . ($searchSymbol ?: 'all') . ':' . ($statusFilter ?: 'all');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($searchSymbol, $statusFilter) {
            // Get stocks to analyze (filtered by search)
            $stocks = $this->getStocksWithFinancialResultsData($searchSymbol);

            // Analyze each stock
            $analyzed = [];
            foreach ($stocks as $stock) {
                $analysis = $this->analyzeStockFinancialResults($stock->symbol, $stock->id);

                // Filter by status if requested
                if ($statusFilter === 'broken' && $analysis['status'] !== 'BROKEN_SERIES') {
                    continue;
                }

                $analyzed[] = $analysis;
            }

            // Calculate summary
            $summary = $this->calculateFinancialSummary($analyzed);

            return [
                'type' => 'quarterly',
                'summary' => $summary,
                'stocks' => $analyzed,
            ];
        });
    }

    /**
     * Get detailed period history for a stock from financial_results
     *
     * @param string $stockId
     * @return array
     */
    public function getFinancialResultsPeriodHistory(string $stockId): array
    {
        $stock = DB::table('stocks')->where('id', $stockId)->first(['id', 'symbol']);

        if (!$stock) {
            return ['error' => 'Stock not found'];
        }

        // Get distinct periods for this stock from financial_results (quarterly only)
        $periods = DB::table('financial_results')
            ->where('stock_id', $stockId)
            ->where('period_type', 'quarterly')
            ->select('period_name')
            ->distinct()
            ->orderBy('period_name')
            ->pluck('period_name')
            ->toArray();

        return $this->buildFinancialResultsPeriodMatrix($stock->symbol, $periods);
    }

    // ========== FINANCIAL RESULTS HELPER METHODS ==========

    private function getStocksWithFinancialResultsData(?string $searchSymbol): array
    {
        $query = DB::table('stocks')->where('is_active', true);

        if ($searchSymbol) {
            $query->where('symbol', 'ILIKE', "%{$searchSymbol}%");
        }

        return $query->orderBy('symbol')->get(['id', 'symbol'])->toArray();
    }

    /**
     * Analyze stock financial results periods (quarterly only)
     */
    private function analyzeStockFinancialResults(string $symbol, string $stockId): array
    {
        // Get all quarterly periods for this stock
        $periods = DB::table('financial_results')
            ->where('stock_id', $stockId)
            ->where('period_type', 'quarterly')
            ->select('period_name')
            ->distinct()
            ->orderBy('period_name')
            ->pluck('period_name')
            ->toArray();

        if (empty($periods)) {
            return [
                'symbol' => $symbol,
                'stockId' => $stockId,
                'type' => 'quarterly',
                'firstPeriod' => null,
                'latestPeriod' => null,
                'expectedPeriods' => 0,
                'availablePeriods' => 0,
                'missingPeriods' => 0,
                'missingList' => [],
                'status' => 'NO_DATA',
            ];
        }

        // Parse periods to extract year and quarter info
        $periodData = [];
        foreach ($periods as $period) {
            $parsed = $this->parseFinancialResultsPeriod($period);
            if ($parsed) {
                $periodData[] = $parsed;
            }
        }

        if (empty($periodData)) {
            return [
                'symbol' => $symbol,
                'stockId' => $stockId,
                'type' => 'quarterly',
                'firstPeriod' => null,
                'latestPeriod' => null,
                'expectedPeriods' => 0,
                'availablePeriods' => 0,
                'missingPeriods' => 0,
                'missingList' => [],
                'status' => 'NO_DATA',
            ];
        }

        // Sort by year and quarter
        usort($periodData, function ($a, $b) {
            if ($a['year'] !== $b['year']) {
                return $a['year'] <=> $b['year'];
            }
            return $a['quarter'] <=> $b['quarter'];
        });

        // Build expected sequence
        $firstYear = $periodData[0]['year'];
        $latestYear = $periodData[count($periodData) - 1]['year'];
        $firstQuarter = $periodData[0]['quarter'];
        $latestQuarter = $periodData[count($periodData) - 1]['quarter'];

        // Generate expected periods
        $expectedPeriods = $this->generateExpectedPeriods($firstYear, $firstQuarter, $latestYear, $latestQuarter, 'quarterly');
        $availableSet = $this->getPeriodSet($periodData, 'quarterly');

        // Detect missing periods
        $missingPeriods = array_diff($expectedPeriods, $availableSet);
        sort($missingPeriods);

        // Determine status
        $status = empty($missingPeriods) ? 'HEALTHY' : 'BROKEN_SERIES';

        // Format first/latest periods
        $firstPeriod = $this->formatPeriodLabel($firstYear, $firstQuarter, 'quarterly');
        $latestPeriod = $this->formatPeriodLabel($latestYear, $latestQuarter, 'quarterly');

        return [
            'symbol' => $symbol,
            'stockId' => $stockId,
            'type' => 'quarterly',
            'firstPeriod' => $firstPeriod,
            'latestPeriod' => $latestPeriod,
            'expectedPeriods' => count($expectedPeriods),
            'availablePeriods' => count($periodData),
            'missingPeriods' => count($missingPeriods),
            'missingList' => $missingPeriods,
            'status' => $status,
        ];
    }

    /**
     * Parse financial_results period name (format: "YYYY-Q1", "YYYY-Q2", etc.)
     */
    private function parseFinancialResultsPeriod(string $periodName): ?array
    {
        $periodName = trim($periodName);

        // Format: "YYYY-Q1", "YYYY-Q2", "YYYY-Q3", "YYYY-Q4"
        if (preg_match('/^(\d{4})-Q([1-4])$/', $periodName, $matches)) {
            return [
                'name' => $periodName,
                'year' => (int)$matches[1],
                'quarter' => (int)$matches[2],
            ];
        }

        return null;
    }

    /**
     * Build period matrix for financial_results periods
     */
    private function buildFinancialResultsPeriodMatrix(string $symbol, array $periodStrings): array
    {
        if (empty($periodStrings)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        // Parse all period strings
        $periods = [];
        foreach ($periodStrings as $periodStr) {
            $parsed = $this->parseFinancialResultsPeriod($periodStr);
            if ($parsed) {
                $periods[] = $parsed;
            }
        }

        if (empty($periods)) {
            return ['symbol' => $symbol, 'years' => []];
        }

        // Sort by year
        usort($periods, function ($a, $b) {
            return $a['year'] <=> $b['year'];
        });

        return $this->buildQuarterlyMatrixFromPeriods($symbol, $periods);
    }
}
