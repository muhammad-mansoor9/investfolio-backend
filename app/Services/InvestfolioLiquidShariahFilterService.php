<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvestfolioLiquidShariahFilterService
{
    private const VOLUME_LOOKBACK_TRADING_DAYS = 30;
    private const MIN_AVG_VOLUME = 10000;

    private ?array $avgVolumes = null;

    public function getStocksWhereClause(): string
    {
        return $this->buildWhereClause('sp.price');
    }

    public function getStockPricesWhereClause(): string
    {
        return $this->buildWhereClause('lc.close');
    }

    public function passesFilter($stock): bool
    {
        if (!$stock->is_shariah) {
            return false;
        }

        $freeFloat = (float) ($stock->free_float ?? 0);
        $sharesOutstanding = (float) ($stock->total_shares_outstanding ?? 0);

        if ($sharesOutstanding <= 0) {
            return false;
        }

        $freeFloatPercent = $freeFloat / $sharesOutstanding;

        $condition1 = $freeFloatPercent >= 0.15 && $freeFloatPercent <= 0.70;

        $marketCap = (float) ($stock->market_cap ?? 0);
        $condition2 = $marketCap > 50000000000 && $freeFloatPercent > 0.70;

        if (!$condition1 && !$condition2) {
            return false;
        }

        $stockId = $stock->stock_id ?? $stock->id ?? null;

        return $stockId !== null
            && ($this->getAvgVolumes()[$stockId] ?? 0) >= self::MIN_AVG_VOLUME;
    }

    private function buildWhereClause(string $priceColumn): string
    {
        return "(
            s.is_shariah = true
            AND (
                (s.free_float::numeric >= (s.total_shares_outstanding::numeric * 0.15)
                 AND s.free_float::numeric <= (s.total_shares_outstanding::numeric * 0.70))
                OR
                (({$priceColumn}::numeric * s.total_shares_outstanding::numeric) > 50000000000
                 AND s.free_float::numeric > (s.total_shares_outstanding::numeric * 0.70))
            )
            AND (
                SELECT COALESCE(SUM(vol.volume), 0) / " . self::VOLUME_LOOKBACK_TRADING_DAYS . ".0
                FROM stock_prices vol
                WHERE vol.stock_id = s.id
                  AND vol.date >= (" . $this->volumeWindowStartSql() . ")
            ) >= " . self::MIN_AVG_VOLUME . "
        )";
    }

    // Window is the last N market-wide trading dates, and the sum is divided by N, so days a
    // stock didn't trade count as zero volume instead of being skipped.
    private function volumeWindowStartSql(): string
    {
        return "SELECT MIN(d.date) FROM (
                    SELECT DISTINCT date FROM stock_prices
                    ORDER BY date DESC
                    LIMIT " . self::VOLUME_LOOKBACK_TRADING_DAYS . "
                ) d";
    }

    private function getAvgVolumes(): array
    {
        if ($this->avgVolumes === null) {
            $rows = DB::select("
                SELECT stock_id, SUM(volume) / " . self::VOLUME_LOOKBACK_TRADING_DAYS . ".0 AS avg_volume
                FROM stock_prices
                WHERE date >= (" . $this->volumeWindowStartSql() . ")
                GROUP BY stock_id
            ");

            $this->avgVolumes = collect($rows)
                ->mapWithKeys(fn ($row) => [$row->stock_id => (float) $row->avg_volume])
                ->all();
        }

        return $this->avgVolumes;
    }
}
