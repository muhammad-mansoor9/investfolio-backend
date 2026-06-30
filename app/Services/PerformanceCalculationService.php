<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Stock;
use App\Models\PerformanceCriteria;
use Illuminate\Support\Facades\DB;

class PerformanceCalculationService
{
    /**
     * Calculate score for a specific criteria and stock
     */
    public function calculateScore(Stock $stock, PerformanceCriteria $criteria): array
    {
        $calculationMethod = $criteria->getCalculationMethod();

        try {
            $value = $this->calculateValue($stock, $calculationMethod);

            if ($value === null) {
                return [
                    'value' => null,
                    'score' => null,
                    'weighted_score' => null,
                ];
            }

            $score = $this->applyScoring(
                $value,
                $criteria->getScoringType(),
                $criteria->getReferenceValues(),
                $criteria->isHigherBetter()
            );

            $weightedScore = $score * $criteria->getWeightage();

            return [
                'value' => round($value, 2),
                'score' => round($score, 2),
                'weighted_score' => round($weightedScore, 2),
            ];
        } catch (\Exception $e) {
            return [
                'value' => null,
                'score' => null,
                'weighted_score' => null,
            ];
        }
    }

    /**
     * Calculate the actual value based on the calculation method
     */
    protected function calculateValue(Stock $stock, string $method): ?float
    {
        return match($method) {
            'revenue_cagr' => $this->calculateRevenueCagr($stock),
            'net_income_cagr' => $this->calculateNetIncomeCagr($stock),
            'operating_income_cagr' => $this->calculateOperatingIncomeCagr($stock),
            'gross_margin' => $this->calculateGrossMargin($stock),
            'operating_margin' => $this->calculateOperatingMargin($stock),
            'net_profit_margin' => $this->calculateNetProfitMargin($stock),
            'roe' => $this->calculateROE($stock),
            'eps_comparison' => $this->calculateEPSComparison($stock),
            'nfat' => $this->calculateNFAT($stock),
            'inventory_turnover' => $this->calculateInventoryTurnover($stock),
            'days_receivable' => $this->calculateDaysReceivable($stock),
            'cash_conversion_cycle' => $this->calculateCashConversionCycle($stock),
            'total_debt_comparison' => $this->calculateTotalDebtComparison($stock),
            'debt_to_equity' => $this->calculateDebtToEquity($stock),
            'interest_coverage' => $this->calculateInterestCoverage($stock),
            'tax_ratio' => $this->calculateTaxRatio($stock),
            'current_ratio' => $this->calculateCurrentRatio($stock),
            'cash_per_share' => $this->calculateCashPerShare($stock),
            'cash_to_debt' => $this->calculateCashToDebt($stock),
            'cfo_comparison' => $this->calculateCFOComparison($stock),
            'ccaf_vs_cpat' => $this->calculateCCAFvsCPAT($stock),
            'net_change_cash' => $this->calculateNetChangeCash($stock),
            'fcf_comparison' => $this->calculateFCFComparison($stock),
            'fcf_per_share_comparison' => $this->calculateFCFPerShareComparison($stock),
            'fcf_per_sale' => $this->calculateFCFPerSale($stock),
            'fcf_per_cfo' => $this->calculateFCFPerCFO($stock),
            'cash_roic' => $this->calculateCashROIC($stock),
            'pe_ratio' => $this->calculatePERatio($stock),
            'earnings_yield' => $this->calculateEarningsYield($stock),
            'peg_ratio' => $this->calculatePEGRatio($stock),
            'ps_ratio' => $this->calculatePSRatio($stock),
            'pb_ratio' => $this->calculatePBRatio($stock),
            'graham_value' => $this->calculateGrahamValue($stock),
            'dividend_yield' => $this->calculateDividendYield($stock),
            'ev_ebitda' => $this->calculateEVEBITDA($stock),
            default => $this->getDummyValue($stock, $method),
        };
    }

    /**
     * Apply scoring based on scoring type
     */
    protected function applyScoring(
        float $value,
        string $scoringType,
        ?array $referenceValues,
        bool $isHigherBetter
    ): float {
        return match($scoringType) {
            'value_range' => $this->scoreValueRange($value, $referenceValues, $isHigherBetter),
            'single_limit' => $this->scoreSingleLimit($value, $referenceValues[0] ?? 0, $isHigherBetter),
            'avg_comparison' => $this->scoreAvgComparison($value, $isHigherBetter),
            'binary' => $this->scoreBinary($value, $isHigherBetter),
            default => 5.0,
        };
    }

    /**
     * Score based on value range (linear regression between min and max)
     */
    protected function scoreValueRange(float $value, array $referenceValues, bool $isHigherBetter): float
    {
        if (count($referenceValues) < 2) {
            return 5.0;
        }

        [$lowerRef, $upperRef] = $referenceValues;

        if ($upperRef == $lowerRef) {
            return 5.0;
        }

        // Calculate score using linear regression
        $slope = (10 - 3) / ($upperRef - $lowerRef);
        $score = 3 + $slope * ($value - $lowerRef);

        // For "lower is better" metrics, invert the score
        if (!$isHigherBetter) {
            $score = 13 - $score; // Invert around midpoint (6.5)
        }

        // Clamp between 3 and 10
        return max(3, min(10, $score));
    }

    /**
     * Score based on single limit threshold
     */
    protected function scoreSingleLimit(float $value, float $limit, bool $isHigherBetter): float
    {
        if ($isHigherBetter) {
            return $value >= $limit ? 10 : 3;
        } else {
            return $value <= $limit ? 10 : 3;
        }
    }

    /**
     * Score based on average comparison (3Y vs 5Y)
     */
    protected function scoreAvgComparison(float $value, bool $isHigherBetter): float
    {
        // Value represents percentage difference
        // If difference > 10%, score 10, otherwise score based on difference
        $absDiff = abs($value);

        if ($absDiff >= 10) {
            return 10;
        } else {
            return 3 + (1 - ($absDiff / 10)) * 7;
        }
    }

    /**
     * Score based on binary (positive/negative)
     */
    protected function scoreBinary(float $value, bool $isHigherBetter): float
    {
        if ($isHigherBetter) {
            return $value > 0 ? 10 : 3;
        } else {
            return $value < 0 ? 10 : 3;
        }
    }

    // ========================================================================
    // CALCULATION METHODS - IMPLEMENTED
    // ========================================================================

    protected function calculateRevenueCagr(Stock $stock): ?float
    {
        $revenues = $this->getFinancialValues($stock, 'Revenue', 'Income Statement', 5);
        if (count($revenues) < 2) return null;

        return $this->calculateCAGR($revenues);
    }

    protected function calculateNetIncomeCagr(Stock $stock): ?float
    {
        $netIncomes = $this->getFinancialValues($stock, 'Net Income', 'Income Statement', 5);
        if (count($netIncomes) < 2) return null;

        return $this->calculateCAGR($netIncomes);
    }

    protected function calculateOperatingIncomeCagr(Stock $stock): ?float
    {
        $operatingIncomes = $this->getFinancialValues($stock, 'Operating Income', 'Income Statement', 5);
        if (count($operatingIncomes) < 2) return null;

        return $this->calculateCAGR($operatingIncomes);
    }

    protected function calculateGrossMargin(Stock $stock): ?float
    {
        $revenue = $this->getLatestFinancialValue($stock, 'Revenue', 'Income Statement');
        $cogs = $this->getLatestFinancialValue($stock, 'Cost of Revenue', 'Income Statement');

        if ($revenue === null || $cogs === null || $revenue == 0) return null;

        return (($revenue - $cogs) / $revenue) * 100;
    }

    protected function calculateOperatingMargin(Stock $stock): ?float
    {
        $revenue = $this->getLatestFinancialValue($stock, 'Revenue', 'Income Statement');
        $operatingIncome = $this->getLatestFinancialValue($stock, 'Operating Income', 'Income Statement');

        if ($revenue === null || $operatingIncome === null || $revenue == 0) return null;

        return ($operatingIncome / $revenue) * 100;
    }

    protected function calculateNetProfitMargin(Stock $stock): ?float
    {
        $revenue = $this->getLatestFinancialValue($stock, 'Revenue', 'Income Statement');
        $netIncome = $this->getLatestFinancialValue($stock, 'Net Income', 'Income Statement');

        if ($revenue === null || $netIncome === null || $revenue == 0) return null;

        return ($netIncome / $revenue) * 100;
    }

    protected function calculateROE(Stock $stock): ?float
    {
        $netIncome = $this->getLatestFinancialValue($stock, 'Net Income', 'Income Statement');
        $equity = $this->getLatestFinancialValue($stock, 'Total Equity', 'Balance Sheet');

        if ($netIncome === null || $equity === null || $equity == 0) return null;

        return ($netIncome / $equity) * 100;
    }

    protected function calculateEPSComparison(Stock $stock): ?float
    {
        $epsValues = $this->getFinancialValues($stock, 'EPS', 'Income Statement', 5);
        if (count($epsValues) < 5) return null;

        $avg3Y = $this->calculateAverage(array_slice($epsValues, 0, 3));
        $avg5Y = $this->calculateAverage($epsValues);

        if ($avg5Y == 0) return null;

        return abs(($avg3Y - $avg5Y) / $avg5Y) * 100;
    }

    protected function calculateNFAT(Stock $stock): ?float
    {
        $revenue = $this->getLatestFinancialValue($stock, 'Revenue', 'Income Statement');
        $nfa = $this->getLatestFinancialValue($stock, 'Net Fixed Assets', 'Balance Sheet');

        if ($revenue === null || $nfa === null || $nfa == 0) return null;

        return $revenue / $nfa;
    }

    protected function calculateCurrentRatio(Stock $stock): ?float
    {
        $currentAssets = $this->getLatestFinancialValue($stock, 'Current Assets', 'Balance Sheet');
        $currentLiabilities = $this->getLatestFinancialValue($stock, 'Current Liabilities', 'Balance Sheet');

        if ($currentAssets === null || $currentLiabilities === null || $currentLiabilities == 0) return null;

        return $currentAssets / $currentLiabilities;
    }

    protected function calculateDebtToEquity(Stock $stock): ?float
    {
        $totalDebt = $this->getLatestFinancialValue($stock, 'Total Debt', 'Balance Sheet');
        $equity = $this->getLatestFinancialValue($stock, 'Total Equity', 'Balance Sheet');

        if ($totalDebt === null || $equity === null || $equity == 0) return null;

        return $totalDebt / $equity;
    }

    protected function calculateInterestCoverage(Stock $stock): ?float
    {
        $operatingIncome = $this->getLatestFinancialValue($stock, 'Operating Income', 'Income Statement');
        $interestExpense = $this->getLatestFinancialValue($stock, 'Interest Expense', 'Income Statement');

        if ($operatingIncome === null || $interestExpense === null || $interestExpense == 0) return null;

        return $operatingIncome / $interestExpense;
    }

    protected function calculatePERatio(Stock $stock): ?float
    {
        $price = $stock->last_price ?? $stock->current_price;
        $eps = $this->getLatestFinancialValue($stock, 'EPS', 'Income Statement');

        if ($price === null || $eps === null || $eps == 0) return null;

        return $price / $eps;
    }

    // ========================================================================
    // CALCULATION METHODS - PLACEHOLDERS (Return dummy values)
    // ========================================================================

    protected function calculateInventoryTurnover(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'inventory_turnover');
    }

    protected function calculateDaysReceivable(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'days_receivable');
    }

    protected function calculateCashConversionCycle(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'cash_conversion_cycle');
    }

    protected function calculateTotalDebtComparison(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'total_debt_comparison');
    }

    protected function calculateTaxRatio(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'tax_ratio');
    }

    protected function calculateCashPerShare(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'cash_per_share');
    }

    protected function calculateCashToDebt(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'cash_to_debt');
    }

    protected function calculateCFOComparison(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'cfo_comparison');
    }

    protected function calculateCCAFvsCPAT(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'ccaf_vs_cpat');
    }

    protected function calculateNetChangeCash(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'net_change_cash');
    }

    protected function calculateFCFComparison(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'fcf_comparison');
    }

    protected function calculateFCFPerShareComparison(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'fcf_per_share_comparison');
    }

    protected function calculateFCFPerSale(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'fcf_per_sale');
    }

    protected function calculateFCFPerCFO(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'fcf_per_cfo');
    }

    protected function calculateCashROIC(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'cash_roic');
    }

    protected function calculateEarningsYield(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'earnings_yield');
    }

    protected function calculatePEGRatio(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'peg_ratio');
    }

    protected function calculatePSRatio(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'ps_ratio');
    }

    protected function calculatePBRatio(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'pb_ratio');
    }

    protected function calculateGrahamValue(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'graham_value');
    }

    protected function calculateDividendYield(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'dividend_yield');
    }

    protected function calculateEVEBITDA(Stock $stock): ?float
    {
        return $this->getDummyValue($stock, 'ev_ebitda');
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Get dummy value for testing (returns consistent value based on stock symbol)
     */
    protected function getDummyValue(Stock $stock, string $method): float
    {
        $hash = crc32($stock->symbol . $method);
        return 5 + ($hash % 10); // Returns value between 5 and 14
    }

    /**
     * Get multiple years of financial data for a specific identifier
     */
    protected function getFinancialValues(Stock $stock, string $identifier, string $statement, int $years = 5): array
    {
        $data = DB::table('financial_data')
            ->where('symbol', $stock->symbol)
            ->where('statement', $statement)
            ->where('identifier', $identifier)
            ->orderBy('period', 'desc')
            ->limit($years)
            ->pluck('value')
            ->toArray();

        return array_map('floatval', $data);
    }

    /**
     * Get the latest financial value for a specific identifier
     */
    protected function getLatestFinancialValue(Stock $stock, string $identifier, string $statement): ?float
    {
        $value = DB::table('financial_data')
            ->where('symbol', $stock->symbol)
            ->where('statement', $statement)
            ->where('identifier', $identifier)
            ->orderBy('period', 'desc')
            ->value('value');

        return $value !== null ? (float) $value : null;
    }

    /**
     * Calculate CAGR from an array of values
     */
    protected function calculateCAGR(array $values): ?float
    {
        $count = count($values);
        if ($count < 2) return null;

        $startValue = end($values);
        $endValue = reset($values);

        if ($startValue <= 0) return null;

        $years = $count - 1;
        $cagr = (pow(($endValue / $startValue), (1 / $years)) - 1) * 100;

        return $cagr;
    }

    /**
     * Calculate average of an array
     */
    protected function calculateAverage(array $values): float
    {
        if (empty($values)) return 0;
        return array_sum($values) / count($values);
    }
}
