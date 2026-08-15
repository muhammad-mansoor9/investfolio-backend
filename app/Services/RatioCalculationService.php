<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RatioCalculationService
{
    private const REDIS_TTM_PREFIX = 'ttm:';
    private const CACHE_TTL = 86400; // 24 hours
    private array $runtimeCache = [];

    public function preloadFinancialData(Collection $stocks): void
    {
        $symbols = $stocks->pluck('symbol')->toArray();

        if (empty($symbols)) {
            return;
        }

        // Fetch 8 quarters per symbol/identifier (current TTM + previous TTM)
        $financialData = DB::table('financial_data')
            ->whereIn('symbol', $symbols)
            ->where('type', 'QUARTERLY')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->select('symbol', 'identifier', 'value', 'col_order')
            ->orderBy('col_order', 'desc')
            ->get();

        if ($financialData->isEmpty()) {
            return;
        }

        // Group by symbol and identifier
        $grouped = $financialData->groupBy('symbol')->map(function($symbolData) {
            return $symbolData->groupBy('identifier')->map(function($quarterlyValues) {
                return $quarterlyValues->take(8)->values(); // Get 8 most recent quarters
            });
        });

        $cacheData = [];

        // Calculate TTM for current (Q1-Q4) and previous year (Q5-Q8)
        foreach ($grouped as $symbol => $identifiers) {
            foreach ($identifiers as $identifier => $quarters) {
                // Current TTM (first 4 quarters)
                $currentQuarters = $quarters->take(4);
                $currentTTM = $this->calculateTTMValue($identifier, $currentQuarters);
                if ($currentTTM !== null) {
                    $cacheKey = "{$symbol}|{$identifier}";
                    $cacheData[$cacheKey] = $currentTTM;
                    $this->runtimeCache[$cacheKey] = $currentTTM;
                }

                // Previous year TTM (quarters 5-8)
                $previousQuarters = $quarters->slice(4, 4);
                if ($previousQuarters->count() === 4) {
                    $previousTTM = $this->calculateTTMValue($identifier, $previousQuarters);
                    if ($previousTTM !== null) {
                        $cacheKey = "{$symbol}|{$identifier}|prev";
                        $cacheData[$cacheKey] = $previousTTM;
                        $this->runtimeCache[$cacheKey] = $previousTTM;
                    }
                }
            }
        }

        // Store all in Redis (batch operation)
        if (!empty($cacheData)) {
            foreach ($cacheData as $key => $value) {
                Redis::setex(
                    self::REDIS_TTM_PREFIX . $key,
                    self::CACHE_TTL,
                    $value
                );
            }
        }

        Log::info('Financial data preloaded (TTM from quarterly)', [
            'stocks' => count($symbols),
            'cached_values' => count($cacheData)
        ]);
    }

    private function calculateTTMValue(string $identifier, Collection $quarters): ?float
    {
        $values = [];
        foreach ($quarters as $quarter) {
            $value = $this->parseValue($quarter->value);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return !empty($values) ? $this->calculateTTM($identifier, $values) : null;
    }

    private function calculateTTM(string $identifier, array $values): ?float
    {
        $category = MetricClassifier::classify($identifier);

        return match ($category) {
            'sum' => round(array_sum($values), 2),
            'latest' => round(end($values), 2),
            'average' => round(array_sum($values) / count($values), 2),
            'margin' => round(array_sum($values) / count($values), 2), // Recalculated, not averaged
            'days' => round(array_sum($values) / count($values), 2),
            'growth' => round(array_sum($values) / count($values), 2),
            default => round(array_sum($values) / count($values), 2),
        };
    }

    private function parseValue($value): ?float
    {
        if (empty($value) ||
            in_array($value, ['—', '-', 'null', '[NULL]'], true) ||
            strtoupper($value) === 'NULL') {
            return null;
        }

        $cleanValue = str_replace(',', '', trim($value));
        return is_numeric($cleanValue) ? (float) $cleanValue : null;
    }

    public function clearCache(): void
    {
        $this->runtimeCache = [];
    }

    public function calculateRatio(Stock $stock, string $ratioName): ?float
    {
        $value = $this->getValue($stock->symbol, $ratioName);
        if ($value !== null) {
            return $value;
        }

        $identifier = $this->getIdentifierMapping($ratioName);
        if ($identifier !== $ratioName) {
            $value = $this->getValue($stock->symbol, $identifier);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->calculateCustomRatio($stock, $ratioName);
    }

    private function getValue(string $symbol, string $identifier): ?float
    {
        $key = "{$symbol}|{$identifier}";

        // Check runtime cache first
        if (isset($this->runtimeCache[$key])) {
            return $this->runtimeCache[$key];
        }

        // Check Redis
        $redisKey = self::REDIS_TTM_PREFIX . $key;
        $cached = Redis::get($redisKey);
        if ($cached !== null) {
            $value = (float) $cached;
            $this->runtimeCache[$key] = $value;
            return $value;
        }

        // Fallback: query database (should rarely happen if preloaded)
        Log::warning('Cache miss for TTM value', ['symbol' => $symbol, 'identifier' => $identifier]);

        $quarters = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->where('type', 'QUARTERLY')
            ->where('identifier', $identifier)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->orderByDesc('col_order')
            ->take(4)
            ->pluck('value')
            ->toArray();

        if (empty($quarters)) {
            return null;
        }

        $values = [];
        foreach ($quarters as $quarter) {
            $value = $this->parseValue($quarter);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        if (empty($values)) {
            return null;
        }

        $ttmValue = $this->calculateTTM($identifier, $values);
        if ($ttmValue !== null) {
            $this->runtimeCache[$key] = $ttmValue;
            Redis::setex($redisKey, self::CACHE_TTL, $ttmValue);
        }

        return $ttmValue;
    }

    private function get2ndLastValue(string $symbol, string $identifier): ?float
    {
        $key = "{$symbol}|{$identifier}|prev";

        // Check runtime cache first
        if (isset($this->runtimeCache[$key])) {
            return $this->runtimeCache[$key];
        }

        // Check Redis
        $redisKey = self::REDIS_TTM_PREFIX . $key;
        $cached = Redis::get($redisKey);
        if ($cached !== null) {
            $value = (float) $cached;
            $this->runtimeCache[$key] = $value;
            return $value;
        }

        // Fallback: query database
        Log::warning('Cache miss for previous year TTM', ['symbol' => $symbol, 'identifier' => $identifier]);

        $quarters = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->where('type', 'QUARTERLY')
            ->where('identifier', $identifier)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->orderByDesc('col_order')
            ->skip(4)
            ->take(4)
            ->pluck('value')
            ->toArray();

        if (empty($quarters)) {
            return null;
        }

        $values = [];
        foreach ($quarters as $quarter) {
            $value = $this->parseValue($quarter);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        if (empty($values)) {
            return null;
        }

        $ttmValue = $this->calculateTTM($identifier, $values);
        if ($ttmValue !== null) {
            $this->runtimeCache[$key] = $ttmValue;
            Redis::setex($redisKey, self::CACHE_TTL, $ttmValue);
        }

        return $ttmValue;
    }

    private function getIdentifierMapping(string $ratioName): string
    {
        $mappings = [
            'Dividend Payout Ratio' => 'Payout Ratio',
            'Interest Coverage Ratio' => 'EBIT / Interest Expense',
        ];

        return $mappings[$ratioName] ?? $ratioName;
    }

    private function calculateCustomRatio(Stock $stock, string $ratioName): ?float
    {
        return match ($ratioName) {
            'Working Capital' => $this->calculateWorkingCapital($stock),
            'PEG Ratio' => $this->calculatePEGRatio($stock),
            'EBITDA Growth (YoY)' => $this->calculateEBITDAGrowthYoY($stock),
            'Book Value Growth' => $this->calculateBookValueGrowth($stock),
            'Operating Cash Flow Ratio' => $this->calculateOperatingCashFlowRatio($stock),
            'Revenue Growth (YoY)' => $this->calculateRevenueGrowthYoY($stock),
            'Revenue Growth (3Y CAGR)' => $this->calculateRevenueGrowthCAGR($stock, 3),
            'Revenue Growth (5Y CAGR)' => $this->calculateRevenueGrowthCAGR($stock, 5),
            'EPS Growth (YoY)' => $this->calculateEPSGrowthYoY($stock),
            'EPS Growth (3Y CAGR)' => $this->calculateEPSGrowthCAGR($stock, 3),
            'Total Assets 1Y Growth' => $this->calculateAssetsGrowthYoY($stock),
            default => null,
        };
    }

    /**
     * Working Capital = Current Assets - Current Liabilities
     */
    private function calculateWorkingCapital(Stock $stock): ?float
    {
        $currentAssets = $this->getValue($stock->symbol, 'Total Current Assets');
        $currentLiabilities = $this->getValue($stock->symbol, 'Total Current Liabilities');

        if ($currentAssets !== null && $currentLiabilities !== null) {
            return round($currentAssets - $currentLiabilities, 2);
        }

        return null;
    }

    /**
     * PEG Ratio = P/E / EPS Growth Rate
     */
    private function calculatePEGRatio(Stock $stock): ?float
    {
        $pe = $this->getValue($stock->symbol, 'P/E');
        $epsGrowth = $this->getValue($stock->symbol, 'Diluted EPS 1Y Growth');

        if ($pe !== null && $epsGrowth !== null && $epsGrowth != 0) {
            return round($pe / $epsGrowth, 2);
        }

        return null;
    }

    /**
     * Operating Cash Flow Ratio = Operating Cash Flow / Current Liabilities
     */
    private function calculateOperatingCashFlowRatio(Stock $stock): ?float
    {
        $ocf = $this->getValue($stock->symbol, 'Cash from Operations');
        $currentLiabilities = $this->getValue($stock->symbol, 'Total Current Liabilities');


        if ($ocf !== null && $currentLiabilities !== null && $currentLiabilities != 0) {
            return round($ocf / $currentLiabilities, 2);
        }
        return null;
    }

    private function calculateEBITDAGrowthYoY(Stock $stock): ?float
    {
        $currentEBITDA = $this->getValue($stock->symbol, 'EBITDA');
        $previousEBITDA = $this->get2ndLastValue($stock->symbol, 'EBITDA');

        if ($currentEBITDA !== null && $previousEBITDA !== null && $previousEBITDA != 0) {
            return round((($currentEBITDA - $previousEBITDA) / $previousEBITDA) * 100, 2);
        }

        return null;
    }

    private function calculateBookValueGrowth(Stock $stock): ?float
    {
        $bookValue = $this->getValue($stock->symbol, "Shareholders' Equity");
        $bookValueLastYear = $this->get2ndLastValue($stock->symbol, "Shareholders' Equity");

        if ($bookValue !== null && $bookValueLastYear !== null && $bookValueLastYear != 0) {
            return round((($bookValue - $bookValueLastYear) / $bookValueLastYear) * 100, 2);
        }

        return null;
    }

    private function calculateRevenueGrowthYoY(Stock $stock): ?float
    {
        $current = $this->getValue($stock->symbol, 'Total Revenues');
        $previous = $this->get2ndLastValue($stock->symbol, 'Total Revenues');

        if ($current !== null && $previous !== null && $previous != 0) {
            return round((($current - $previous) / $previous) * 100, 2);
        }

        return null;
    }

    private function calculateRevenueGrowthCAGR(Stock $stock, int $years): ?float
    {
        $quarters = $this->getQuartersData($stock->symbol, 'Total Revenues', $years * 4);

        if (count($quarters) < ($years * 4)) {
            return null;
        }

        $latest = end($quarters);
        $oldest = reset($quarters);

        if ($oldest === null || $oldest <= 0 || $latest === null || $latest <= 0) {
            return null;
        }

        $cagr = (pow($latest / $oldest, 1 / $years) - 1) * 100;
        return is_finite($cagr) ? round($cagr, 2) : null;
    }

    private function calculateEPSGrowthYoY(Stock $stock): ?float
    {
        $current = $this->getValue($stock->symbol, 'Diluted EPS');
        $previous = $this->get2ndLastValue($stock->symbol, 'Diluted EPS');

        if ($current !== null && $previous !== null && $previous != 0) {
            return round((($current - $previous) / $previous) * 100, 2);
        }

        return null;
    }

    private function calculateEPSGrowthCAGR(Stock $stock, int $years): ?float
    {
        $quarters = $this->getQuartersData($stock->symbol, 'Diluted EPS', $years * 4);

        if (count($quarters) < ($years * 4)) {
            return null;
        }

        $latest = end($quarters);
        $oldest = reset($quarters);

        if ($oldest === null || $oldest <= 0 || $latest === null || $latest <= 0) {
            return null;
        }

        $cagr = (pow($latest / $oldest, 1 / $years) - 1) * 100;
        return is_finite($cagr) ? round($cagr, 2) : null;
    }

    private function calculateAssetsGrowthYoY(Stock $stock): ?float
    {
        $current = $this->getValue($stock->symbol, 'Total Assets');
        $previous = $this->get2ndLastValue($stock->symbol, 'Total Assets');

        if ($current !== null && $previous !== null && $previous != 0) {
            return round((($current - $previous) / $previous) * 100, 2);
        }

        return null;
    }

    private function getQuartersData(string $symbol, string $identifier, int $quarterCount): array
    {
        $quarters = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->where('type', 'QUARTERLY')
            ->where('identifier', $identifier)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->orderByDesc('col_order')
            ->take($quarterCount)
            ->pluck('value')
            ->toArray();

        if (empty($quarters)) {
            return [];
        }

        $parsedValues = [];
        foreach ($quarters as $quarter) {
            $value = $this->parseValue($quarter);
            if ($value !== null) {
                $parsedValues[] = $value;
            }
        }

        return array_reverse($parsedValues);
    }
}
