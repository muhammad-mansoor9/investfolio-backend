<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ratio Calculation Service
 * Handles retrieval and calculation of financial ratios for stocks
 */
class RatioCalculationService
{
    private array $cache = [];

    /**
     * Preload all financial data for given stocks in one query
     */
    public function preloadFinancialData(Collection $stocks): void
    {
        $symbols = $stocks->pluck('symbol')->toArray();

        if (empty($symbols)) {
            return;
        }

        $financialData = DB::table('financial_data')
            ->whereIn('symbol', $symbols)
            ->where('type', 'ANNUAL')
            ->where('header', 'LTM')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->select('symbol', 'identifier', 'value')
            ->get();

        foreach ($financialData as $data) {
            $value = $this->parseValue($data->value);

            if ($value !== null) {
                $this->cache["{$data->symbol}|{$data->identifier}"] = $value;
            }
        }

        Log::info('Financial data preloaded', [
            'stocks' => count($symbols),
            'records' => $financialData->count(),
            'cached' => count($this->cache)
        ]);
    }

    /**
     * Parse and clean value from database
     */
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

    /**
     * Clear cache to free memory
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Calculate or retrieve a ratio value for a stock
     */
    public function calculateRatio(Stock $stock, string $ratioName): ?float
    {
        // Try direct lookup first
        $value = $this->getValue($stock->symbol, $ratioName);

        if ($value !== null) {
            return $value;
        }

        // Check if ratio needs identifier mapping
        $identifier = $this->getIdentifierMapping($ratioName);

        if ($identifier !== $ratioName) {
            $value = $this->getValue($stock->symbol, $identifier);

            if ($value !== null) {
                return $value;
            }
        }

        // Calculate custom ratios
        return $this->calculateCustomRatio($stock, $ratioName);
    }

    /**
     * Get value from cache or database
     */
    private function getValue(string $symbol, string $identifier): ?float
    {
        $key = "{$symbol}|{$identifier}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Try LTM first
        $result = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->where('type', 'ANNUAL')
            ->where('identifier', $identifier)
            ->where('header', 'LTM')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->value('value');

        // If LTM not found, fallback to right-most column by col_order
        if (!$result) {
            $result = DB::table('financial_data')
                ->where('symbol', $symbol)
                ->where('type', 'ANNUAL')
                ->where('identifier', $identifier)
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->where('value', '!=', '[NULL]')
                ->orderByDesc('col_order')
                ->value('value');
        }

        if ($result) {
            $value = $this->parseValue($result);
            if ($value !== null) {
                $this->cache[$key] = $value;
                return $value;
            }
        }

        return null;
    }

    private function get2ndLastValue(string $symbol, string $identifier): ?float
    {
        $key = "{$symbol}|{$identifier}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Get second last value by col_order
        $result = DB::table('financial_data')
            ->where('symbol', $symbol)
            ->where('type', 'ANNUAL')
            ->where('identifier', $identifier)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '[NULL]')
            ->orderByDesc('col_order')
            ->skip(1)  // skip the last column
            ->value('value');  // take second last

        if ($result) {
            $value = $this->parseValue($result);
            if ($value !== null) {
                $this->cache[$key] = $value;
                return $value;
            }
        }

        return null;
    }

    /**
     * Map ratio names to their database identifiers
     */
    private function getIdentifierMapping(string $ratioName): string
    {
        $mappings = [
            'Revenue Growth (YoY)' => 'Total Revenues % Chg.',
            'Revenue Growth (3Y CAGR)' => 'Revenue 3Y CAGR',
            'Revenue Growth (5Y CAGR)' => 'Revenue 5Y CAGR',
            'EPS Growth (YoY)' => 'Diluted EPS 1Y Growth',
            'EPS Growth (3Y CAGR)' => 'Diluted EPS 3Y CAGR',
            'Total Assets 1Y Growth' => 'Total Assets % Chg.',
            'Dividend Payout Ratio' => 'Payout Ratio',
            'Interest Coverage Ratio' => 'EBIT / Interest Expense',
        ];

        return $mappings[$ratioName] ?? $ratioName;
    }

    /**
     * Calculate complex ratios that need multiple inputs
     */
    private function calculateCustomRatio(Stock $stock, string $ratioName): ?float
    {
        return match ($ratioName) {
            'Working Capital' => $this->calculateWorkingCapital($stock),
            'PEG Ratio' => $this->calculatePEGRatio($stock),
            'EBITDA Growth (YoY)' => $this->calculateEBITDAGrowth($stock),
            'Book Value Growth' => $this->calculateBookValueGrowth($stock),
            'Operating Cash Flow Ratio' => $this->calculateOperatingCashFlowRatio($stock),
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

    private function calculateEBITDAGrowth(Stock $stock): ?float
    {
//        $ebitda = $this->getValue($stock->symbol, 'EBITDA');
//        $ebitdaLastYear = $this->get2ndLastValue($stock->symbol, 'EBITDA');
//        $growth = ($ebitda - $ebitdaLastYear) / $ebitdaLastYear * 100;
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
}
