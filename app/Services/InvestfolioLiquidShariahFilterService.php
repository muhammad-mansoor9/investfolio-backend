<?php

namespace App\Services;

class InvestfolioLiquidShariahFilterService
{

    public function getStocksWhereClause(): string
    {
        return "(
            (s.free_float::numeric >= (s.total_shares_outstanding::numeric * 0.15)
             AND s.free_float::numeric <= (s.total_shares_outstanding::numeric * 0.70))
            OR
            ((sp.price::numeric * s.total_shares_outstanding::numeric) > 50000000000
             AND s.free_float::numeric > (s.total_shares_outstanding::numeric * 0.70))
        )";
    }

    public function getStockPricesWhereClause(): string
    {
        return "(
            (s.free_float::numeric >= (s.total_shares_outstanding::numeric * 0.15)
             AND s.free_float::numeric <= (s.total_shares_outstanding::numeric * 0.70))
            OR
            ((lc.close::numeric * s.total_shares_outstanding::numeric) > 50000000000
             AND s.free_float::numeric > (s.total_shares_outstanding::numeric * 0.70))
        )";
    }

    public function passesFilter($stock): bool
    {
        $freeFloat = (float) ($stock->free_float ?? 0);
        $sharesOutstanding = (float) ($stock->total_shares_outstanding ?? 0);

        if ($sharesOutstanding <= 0) {
            return false;
        }

        $freeFloatPercent = $freeFloat / $sharesOutstanding;

        $condition1 = $freeFloatPercent >= 0.15 && $freeFloatPercent <= 0.70;

        $marketCap = (float) ($stock->market_cap ?? 0);
        $condition2 = $marketCap > 50000000000 && $freeFloatPercent > 0.70;

        return $condition1 || $condition2;
    }
}
