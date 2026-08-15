<?php

namespace App\Services;

/**
 * Classifies financial metrics by their TTM calculation method
 */
class MetricClassifier
{
    // Income Statement items (Sum last 4 quarters)
    private const INCOME_STATEMENT = [
        'Total Revenues', 'Revenue', 'Net Sales', 'Sales',
        'Gross Profit', 'Operating Income', 'EBIT', 'Operating Profit',
        'Net Income', 'Profit After Tax', 'EBT',
        'EBITDA', 'Interest Expense', 'Tax Expense',
        'Diluted EPS', 'Basic EPS', 'EPS',
    ];

    // Cash Flow Statement items (Sum last 4 quarters)
    private const CASH_FLOW = [
        'Cash from Operations', 'Operating Cash Flow', 'OCF',
        'Free Cash Flow', 'FCF',
        'Capital Expenditure', 'CapEx',
        'Dividends Paid',
    ];

    // Balance Sheet items (Use latest quarter only)
    private const BALANCE_SHEET = [
        'Total Assets', 'Total Current Assets', 'Cash', 'Inventory',
        'Accounts Receivable', 'Total Current Liabilities', 'Total Liabilities',
        'Total Debt', 'Total Equity', "Shareholders' Equity", 'Book Value',
        'Working Capital', 'Shares Outstanding', 'NAV',
        // Industry-specific capacity metrics (latest only)
        'Installed Capacity', 'MW', 'Subscribers', 'Fleet Size',
        'Subscribers', 'Subscriber Count', 'Retail Network Size',
        'Crushing Capacity', 'Spindle Capacity', 'Loom Capacity',
        'Reserves', 'Order Book Value',
    ];

    // Margins (Recalculate using TTM totals, never average)
    private const MARGINS = [
        'Gross Profit Margin', 'Gross Margin',
        'Operating Margin', 'Operating Profit Margin',
        'Net Profit Margin', 'Net Margin',
        'EBITDA Margin',
        'FCF Margin', 'Free Cash Flow Margin',
        'OCF / Sales', 'Operating Cash Flow / Sales',
        'CapEx Intensity', 'Capex Intensity',
        'Expense Ratio',
        'Tax Rate', 'Effective Tax Rate',
        'NIM', 'Net Interest Margin',
        'Combined Ratio', 'Loss Ratio', 'Expense Ratio',
    ];

    // Returns (Use average balance sheet values)
    private const RETURNS = [
        'Return on Equity', 'ROE',
        'Return on Assets', 'ROA',
        'Return on Invested Capital', 'ROIC',
        'Asset Turnover', 'Fixed Asset Turnover',
        'Inventory Turnover', 'Receivables Turnover',
        'Cost-to-Income', 'Cost to Income',
    ];

    // Liquidity & Solvency (Use latest balance sheet)
    private const LIQUIDITY = [
        'Current Ratio', 'Quick Ratio', 'Cash Ratio',
        'Loan-to-Deposit', 'Advances-to-Deposits',
        'CASA Ratio', 'Capital Adequacy Ratio', 'CAR',
        'NPL Ratio', 'NPL Coverage', 'Debt Ratio',
        'Debt / Equity', 'Financial Leverage',
        'Operating Cash Flow Ratio',
    ];

    // Days metrics (Average balance sheet × 365 / TTM flow)
    private const DAYS_METRICS = [
        'Days Inventory Outstanding', 'DIO',
        'Days Sales Outstanding', 'DSO',
        'Days Payables Outstanding', 'DPO',
        'Cash Conversion Cycle', 'CCC',
    ];

    // Growth metrics (Compare TTM vs previous TTM)
    private const GROWTH = [
        'Revenue Growth (YoY)', 'Revenue Growth (3Y CAGR)', 'Revenue Growth (5Y CAGR)',
        'EPS Growth (YoY)', 'EPS Growth (3Y CAGR)',
        'EBITDA Growth (YoY)', 'EBITDA Growth (3Y CAGR)',
        'Book Value Growth',
        'Total Assets 1Y Growth',
        'Advance Growth Rate', 'Deposit Growth Rate',
        'Premium Growth Rate',
    ];

    public static function classify(string $identifier): string
    {
        if (self::matches($identifier, self::INCOME_STATEMENT)) {
            return 'sum';
        }

        if (self::matches($identifier, self::CASH_FLOW)) {
            return 'sum';
        }

        if (self::matches($identifier, self::BALANCE_SHEET)) {
            return 'latest';
        }

        if (self::matches($identifier, self::MARGINS)) {
            return 'margin';
        }

        if (self::matches($identifier, self::RETURNS)) {
            return 'average';
        }

        if (self::matches($identifier, self::LIQUIDITY)) {
            return 'latest';
        }

        if (self::matches($identifier, self::DAYS_METRICS)) {
            return 'days';
        }

        if (self::matches($identifier, self::GROWTH)) {
            return 'growth';
        }

        return 'average';
    }

    private static function matches(string $identifier, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (stripos($identifier, $pattern) === 0 || $identifier === $pattern) {
                return true;
            }
        }
        return false;
    }

    public static function getCategories(): array
    {
        return [
            'sum' => 'Sum last 4 quarters',
            'latest' => 'Use latest quarter only',
            'margin' => 'Recalculate from TTM totals',
            'average' => 'Use average balance sheet',
            'days' => 'Average balance sheet × 365 / TTM',
            'growth' => 'Compare TTM vs previous TTM',
        ];
    }
}
